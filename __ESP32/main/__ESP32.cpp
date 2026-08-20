#include <atomic>
#include <cstdarg>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <type_traits>
#include <utility>
#include <variant>
#include <vector>

#include "cJSON.h"
#include "esp_crt_bundle.h"
#include "esp_attr.h"
#include "esp_event.h"
#include "driver/gpio.h"
#include "driver/ledc.h"
#include "esp_http_client.h"
#include "esp_heap_caps.h"
#include "esp_log.h"
#include "esp_netif.h"
#include "esp_system.h"
#include "esp_wifi.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "instazine.h"
#include "nvs_flash.h"
#include "printer_encoding.h"
#include "settings.h"
#include "usb_printer.h"

using ContentItem = std::variant<banner, divider, textline, article>;

std::vector<ContentItem> Content;

static const char *TAG = "wifi_client";
static constexpr gpio_num_t ARCADE_BUTTON_PIN = GPIO_NUM_4;
static constexpr gpio_num_t ARCADE_LED_PIN = GPIO_NUM_5;
static constexpr char CONTENT_DIVIDER[] =
    "-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\r\n";
static TaskHandle_t content_request_task_handle;
static std::atomic<bool> led_cycle_enabled{true};
static std::atomic<bool> wifi_connected{false};

struct retained_failure_t
{
    uint32_t signature;
    uint32_t allocation_size;
    uint32_t allocation_caps;
    char allocation_function[32];
};

static constexpr uint32_t RETAINED_FAILURE_SIGNATURE = 0x494E5354;
RTC_NOINIT_ATTR static retained_failure_t retained_failure;
static esp_reset_reason_t boot_reset_reason = ESP_RST_UNKNOWN;
static bool startup_report_sent = false;

static bool post_ping_message(const char *message);

static void report_error(const char *format, ...)
{
    char message[256];
    va_list arguments;
    va_start(arguments, format);
    std::vsnprintf(message, sizeof(message), format, arguments);
    va_end(arguments);
    message[sizeof(message) - 1] = '\0';

    std::printf("Error\n%s\n", message);
    std::fflush(stdout);
    if (wifi_connected.load() && !post_ping_message(message))
    {
        std::printf("Unable to send error to ping API\n");
        std::fflush(stdout);
    }
}

static void allocation_failed_hook(size_t size, uint32_t caps,
                                   const char *function_name)
{
    retained_failure.signature = RETAINED_FAILURE_SIGNATURE;
    retained_failure.allocation_size = static_cast<uint32_t>(size);
    retained_failure.allocation_caps = caps;
    size_t index = 0;
    if (function_name != nullptr)
    {
        while (index + 1 < sizeof(retained_failure.allocation_function) &&
               function_name[index] != '\0')
        {
            retained_failure.allocation_function[index] = function_name[index];
            ++index;
        }
    }
    retained_failure.allocation_function[index] = '\0';
}

static void IRAM_ATTR arcade_button_isr(void *argument)
{
    if (content_request_task_handle == nullptr)
    {
        return;
    }

    BaseType_t higher_priority_task_woken = pdFALSE;
    vTaskNotifyGiveFromISR(content_request_task_handle,
                           &higher_priority_task_woken);
    if (higher_priority_task_woken == pdTRUE)
    {
        portYIELD_FROM_ISR();
    }
}

struct http_response_t
{
    char *data = nullptr;
    size_t length = 0;
};

static bool get_string(const cJSON *object, const char *key,
                       std::string &destination)
{
    const cJSON *value = cJSON_GetObjectItemCaseSensitive(object, key);
    if (!cJSON_IsString(value) || value->valuestring == nullptr)
    {
        return false;
    }

    destination = value->valuestring;
    return true;
}

static bool parse_content(const http_response_t &response)
{
    if (response.data == nullptr || response.length == 0)
    {
        report_error("Empty JSON response");
        return false;
    }

    cJSON *root = cJSON_ParseWithLength(response.data, response.length);
    if (root == nullptr)
    {
        const char *error_position = cJSON_GetErrorPtr();
        char message[96];
        if (error_position != nullptr)
        {
            std::snprintf(message, sizeof(message), "Invalid JSON at byte %td",
                          error_position - response.data);
        }
        else
            std::snprintf(message, sizeof(message), "Invalid JSON");
        report_error("%s", message);
        return false;
    }

    const cJSON *content =
        cJSON_GetObjectItemCaseSensitive(root, "content");
    const cJSON *items = content != nullptr
                             ? cJSON_GetObjectItemCaseSensitive(content,
                                                                "items")
                             : nullptr;

    if (!cJSON_IsObject(content) || !cJSON_IsArray(items))
    {
        cJSON_Delete(root);
        report_error("JSON does not contain content.items");
        return false;
    }

    std::vector<ContentItem> parsed_content;
    const cJSON *item;
    cJSON_ArrayForEach(item, items)
    {
        const cJSON *type =
            cJSON_GetObjectItemCaseSensitive(item, "content-type");
        const cJSON *value =
            cJSON_GetObjectItemCaseSensitive(item, "content-value");

        if (!cJSON_IsString(type) || type->valuestring == nullptr)
        {
            cJSON_Delete(root);
            report_error("Content item has no valid content-type");
            return false;
        }

        if (std::strcmp(type->valuestring, "banner") == 0)
        {
            if (!cJSON_IsString(value) || value->valuestring == nullptr)
            {
                cJSON_Delete(root);
                report_error("Banner has no valid content-value");
                return false;
            }
            parsed_content.emplace_back(banner(value->valuestring));
        }
        else if (std::strcmp(type->valuestring, "divider") == 0)
        {
            if (!cJSON_IsString(value) || value->valuestring == nullptr)
            {
                cJSON_Delete(root);
                report_error("Divider has no valid content-value");
                return false;
            }
            parsed_content.emplace_back(divider(value->valuestring));
        }
        else if (std::strcmp(type->valuestring, "textline") == 0)
        {
            if (!cJSON_IsString(value) || value->valuestring == nullptr)
            {
                cJSON_Delete(root);
                report_error("Textline has no valid content-value");
                return false;
            }
            parsed_content.emplace_back(textline(value->valuestring));
        }
        else if (std::strcmp(type->valuestring, "article") == 0)
        {
            article_content article_value;
            if (!cJSON_IsObject(value) ||
                !get_string(value, "headline", article_value.headline) ||
                !get_string(value, "pic", article_value.pic) ||
                !get_string(value, "text", article_value.text))
            {
                cJSON_Delete(root);
                report_error("Article has an invalid content-value");
                return false;
            }
            parsed_content.emplace_back(article(std::move(article_value)));
        }
        else
        {
            char unknown_type[128];
            std::snprintf(unknown_type, sizeof(unknown_type),
                          "Unknown content-type: %.96s", type->valuestring);
            cJSON_Delete(root);
            report_error("%s", unknown_type);
            return false;
        }
    }

    Content = std::move(parsed_content);
    cJSON_Delete(root);
    return true;
}

static void print_content()
{
    bool first_item = true;

    for (const ContentItem &item : Content)
    {
        if (!first_item)
        {
            std::printf("-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n");
        }
        first_item = false;

        std::visit([](const auto &content_item)
                   {
            using ItemType = std::decay_t<decltype(content_item)>;

            std::printf("content-type: %s\n",
                        content_item.content_type.c_str());

            if constexpr (std::is_same_v<ItemType, article>) {
                std::printf("headline: %s\n",
                            content_item.content_value.headline.c_str());
                std::printf("pic: %s\n",
                            content_item.content_value.pic.c_str());
                std::printf("text: %s\n",
                            content_item.content_value.text.c_str());
            } else {
                std::printf("content-value: %s\n",
                            content_item.content_value.c_str());
            } }, item);
    }
}

static bool printer_write(const char *text, size_t length)
{
    return usb_printer_write(text, length);
}

static bool printer_write(const std::string &text)
{
    return printer_write(text.c_str(), text.size());
}

static esp_err_t http_event_handler(esp_http_client_event_t *event);

static bool is_safe_printer_text(const std::string &text)
{
    if (text.empty() || text.size() > 4096)
        return false;

    for (size_t index = 0; index < text.size();)
    {
        const uint8_t first = static_cast<uint8_t>(text[index]);
        if (first < 0x80)
        {
            if ((first < 0x20 && first != '\n' && first != '\r' &&
                 first != '\t') || first == 0x7f)
                return false;
            ++index;
            continue;
        }

        size_t length = 0;
        uint32_t codepoint = 0;
        uint32_t minimum = 0;
        if ((first & 0xe0) == 0xc0)
        {
            length = 2;
            codepoint = first & 0x1f;
            minimum = 0x80;
        }
        else if ((first & 0xf0) == 0xe0)
        {
            length = 3;
            codepoint = first & 0x0f;
            minimum = 0x800;
        }
        else if ((first & 0xf8) == 0xf0)
        {
            length = 4;
            codepoint = first & 0x07;
            minimum = 0x10000;
        }
        else
            return false;

        if (index + length > text.size())
            return false;
        for (size_t offset = 1; offset < length; ++offset)
        {
            const uint8_t byte = static_cast<uint8_t>(text[index + offset]);
            if ((byte & 0xc0) != 0x80)
                return false;
            codepoint = (codepoint << 6) | (byte & 0x3f);
        }
        if (codepoint < minimum || codepoint > 0x10ffff ||
            (codepoint >= 0xd800 && codepoint <= 0xdfff) ||
            (codepoint >= 0x80 && codepoint <= 0x9f))
            return false;
        index += length;
    }
    return true;
}

static bool has_relative_bmp_url(const std::string &url)
{
    const size_t colon = url.find(':');
    const size_t slash = url.find('/');
    if (url.size() < 5 || url.compare(0, 2, "//") == 0 ||
        url.find('\\') != std::string::npos ||
        url.find('?') != std::string::npos ||
        url.find('#') != std::string::npos ||
        (colon != std::string::npos &&
         (slash == std::string::npos || colon < slash)))
    {
        return false;
    }

    size_t segment_start = url[0] == '/' ? 1 : 0;
    while (segment_start <= url.size())
    {
        const size_t segment_end = url.find('/', segment_start);
        const size_t length = (segment_end == std::string::npos
                                   ? url.size()
                                   : segment_end) - segment_start;
        if (length == 0 ||
            (length == 1 && url[segment_start] == '.') ||
            (length == 2 && url.compare(segment_start, 2, "..") == 0))
            return false;
        if (segment_end == std::string::npos)
            break;
        segment_start = segment_end + 1;
    }

    for (const unsigned char byte : url)
    {
        if (byte < 0x20 || byte == 0x7f)
            return false;
    }

    const size_t suffix = url.size() - 4;
    const auto ascii_lower = [](unsigned char byte)
    {
        return byte >= 'A' && byte <= 'Z' ? byte + ('a' - 'A') : byte;
    };
    return url[suffix] == '.' &&
           ascii_lower(static_cast<unsigned char>(url[suffix + 1])) == 'b' &&
           ascii_lower(static_cast<unsigned char>(url[suffix + 2])) == 'm' &&
           ascii_lower(static_cast<unsigned char>(url[suffix + 3])) == 'p';
}

static std::string url_encode_query_value(const std::string &value)
{
    static constexpr char hex[] = "0123456789ABCDEF";
    std::string encoded;
    encoded.reserve(value.size() * 3);

    for (const unsigned char byte : value)
    {
        const bool is_ascii_letter =
            (byte >= 'A' && byte <= 'Z') || (byte >= 'a' && byte <= 'z');
        const bool is_ascii_digit = byte >= '0' && byte <= '9';
        if (is_ascii_letter || is_ascii_digit || byte == '-' || byte == '_' ||
            byte == '.' || byte == '~')
        {
            encoded.push_back(static_cast<char>(byte));
        }
        else
        {
            encoded.push_back('%');
            encoded.push_back(hex[byte >> 4]);
            encoded.push_back(hex[byte & 0x0f]);
        }
    }
    return encoded;
}

static bool http_get(const std::string &url, http_response_t &response)
{
    const bool is_https = url.compare(0, 8, "https://") == 0;
    esp_http_client_config_t config = {};
    config.url = url.c_str();
    config.method = HTTP_METHOD_GET;
    config.event_handler = http_event_handler;
    config.user_data = &response;
    config.timeout_ms = 10000;
    config.crt_bundle_attach = is_https ? esp_crt_bundle_attach : nullptr;

    esp_http_client_handle_t client = esp_http_client_init(&config);
    if (client == nullptr)
    {
        return false;
    }

    const esp_err_t result = esp_http_client_perform(client);
    const int status = result == ESP_OK
                           ? esp_http_client_get_status_code(client)
                           : 0;
    esp_http_client_cleanup(client);
    return result == ESP_OK && status >= 200 && status < 300;
}

static bool post_ping_message(const char *message)
{
    static constexpr char ping_api_url[] =
        BASE_DOMAIN ":" BASE_PORT PING_API_ROUTE;
    if (message == nullptr)
        return false;

    char body[512];
    size_t length = 0;
    static constexpr char prefix[] = "{\"Message\":\"";
    std::memcpy(body, prefix, sizeof(prefix) - 1);
    length = sizeof(prefix) - 1;
    for (const unsigned char *position =
             reinterpret_cast<const unsigned char *>(message);
         *position != '\0' && length + 4 < sizeof(body); ++position)
    {
        if (*position == '"' || *position == '\\')
            body[length++] = '\\';
        body[length++] = *position < 0x20 ? ' ' : static_cast<char>(*position);
    }
    if (length + 2 >= sizeof(body))
    {
        return false;
    }
    body[length++] = '"';
    body[length++] = '}';
    const int body_length = static_cast<int>(length);

    const bool is_https =
        std::strncmp(ping_api_url, "https://", 8) == 0;
    esp_http_client_config_t config = {};
    config.url = ping_api_url;
    config.method = HTTP_METHOD_POST;
    config.timeout_ms = 10000;
    config.crt_bundle_attach = is_https ? esp_crt_bundle_attach : nullptr;

    esp_http_client_handle_t client = esp_http_client_init(&config);
    if (client == nullptr)
    {
        return false;
    }

    esp_http_client_set_header(client, "Content-Type", "application/json");
    esp_err_t result = esp_http_client_open(client, body_length);
    if (result == ESP_OK)
    {
        const int written =
            esp_http_client_write(client, body, body_length);
        if (written != body_length)
        {
            result = ESP_FAIL;
        }
    }
    if (result == ESP_OK)
    {
        // The ping response body is not used. Reading only the headers avoids
        // failing on servers that omit the final zero-length HTTP chunk.
        esp_http_client_fetch_headers(client);
    }
    const int status_code = esp_http_client_get_status_code(client);
    esp_http_client_cleanup(client);
    return result == ESP_OK && status_code >= 200 && status_code < 300;
}

static bool post_printer_status(uint8_t port_status)
{
    const char *paper_status =
        (port_status & 0x20) != 0 ? "paper empty" : "paper present";
    const char *selection_status =
        (port_status & 0x10) != 0 ? "selected" : "not selected";
    const char *error_status =
        (port_status & 0x08) != 0 ? "no error" : "error";
    char message[128];
    const int length = std::snprintf(
        message, sizeof(message), "0x%02X - %s, %s, %s", port_status,
        paper_status, selection_status, error_status);
    return length > 0 && length < static_cast<int>(sizeof(message)) &&
           post_ping_message(message);
}

static const char *find_json_value(const char *json, const char *key)
{
    const char *position = std::strstr(json, key);
    if (position == nullptr)
    {
        return nullptr;
    }
    position += std::strlen(key);
    while (*position == ' ' || *position == '\r' || *position == '\n' ||
           *position == '\t')
        ++position;
    if (*position++ != ':')
        return nullptr;
    while (*position == ' ' || *position == '\r' || *position == '\n' ||
           *position == '\t')
        ++position;
    return position;
}

static bool parse_json_size(const char *json, const char *key, size_t &value)
{
    const char *position = find_json_value(json, key);
    if (position == nullptr || *position < '0' || *position > '9')
        return false;

    value = 0;
    while (*position >= '0' && *position <= '9')
    {
        const unsigned digit = static_cast<unsigned>(*position++ - '0');
        if (value > (SIZE_MAX - digit) / 10)
            return false;
        value = value * 10 + digit;
    }
    return true;
}

static bool decode_base64_pixels(const char *position, size_t expected_size,
                                 uint8_t *decoded)
{
    uint32_t accumulator = 0;
    unsigned available_bits = 0;
    size_t decoded_size = 0;
    while (*position != '\0' && *position != '"')
    {
        const unsigned char byte =
            static_cast<unsigned char>(*position++);
        int value = -1;
        if (byte >= 'A' && byte <= 'Z')
            value = byte - 'A';
        else if (byte >= 'a' && byte <= 'z')
            value = byte - 'a' + 26;
        else if (byte >= '0' && byte <= '9')
            value = byte - '0' + 52;
        else if (byte == '+')
            value = 62;
        else if (byte == '/')
            value = 63;
        else if (byte == '=')
            break;
        else if (byte == ' ' || byte == '\r' || byte == '\n' ||
                 byte == '\t')
            continue;
        else
            return false;

        accumulator = (accumulator << 6) | value;
        available_bits += 6;
        if (available_bits >= 8)
        {
            if (decoded_size >= expected_size)
                return false;
            available_bits -= 8;
            decoded[decoded_size++] = static_cast<uint8_t>(
                (accumulator >> available_bits) & 0xff);
            accumulator &= available_bits != 0
                               ? (1u << available_bits) - 1u
                               : 0u;
        }
    }
    return decoded_size == expected_size;
}

static bool decode_array_pixels(const char *position, size_t expected_size,
                                uint8_t *decoded)
{
    size_t decoded_size = 0;
    while (true)
    {
        while (*position == ' ' || *position == '\r' || *position == '\n' ||
               *position == '\t')
            ++position;
        if (*position == ']')
            return decoded_size == expected_size;
        if (*position < '0' || *position > '9' ||
            decoded_size >= expected_size)
            return false;

        unsigned value = 0;
        while (*position >= '0' && *position <= '9')
        {
            value = value * 10 + static_cast<unsigned>(*position++ - '0');
            if (value > 255)
                return false;
        }
        decoded[decoded_size++] = static_cast<uint8_t>(value);

        while (*position == ' ' || *position == '\r' || *position == '\n' ||
               *position == '\t')
            ++position;
        if (*position == ',')
            ++position;
        else if (*position != ']')
            return false;
    }
}

static bool parse_picture_json_in_place(char *json, size_t json_size,
                                        size_t &width, size_t &height,
                                        uint8_t *&pixels,
                                        size_t &pixel_size)
{
    if (!parse_json_size(json, "\"width\"", width) ||
        !parse_json_size(json, "\"height\"", height) || width == 0 ||
        height == 0 || width > PRINTER_PIXEL_WIDTH || height > 65535)
        return false;

    const size_t expected_size = ((width + 7) / 8) * height;
    if (expected_size > json_size)
        return false;
    const char *position = find_json_value(json, "\"pixels\"");
    if (position == nullptr)
        return false;
    pixels = reinterpret_cast<uint8_t *>(json);
    pixel_size = expected_size;
    if (*position == '[')
        return decode_array_pixels(position + 1, expected_size, pixels);
    if (*position == '"')
        return decode_base64_pixels(position + 1, expected_size, pixels);
    return false;
}

static bool print_pic_to_printer(const std::string &pic_url)
{
    if (!has_relative_bmp_url(pic_url))
    {
        return false;
    }

    const std::string request_url =
        BASE_DOMAIN ":" BASE_PORT PIC_API_ROUTE "?url=" +
        url_encode_query_value(pic_url);
    size_t width = 0;
    size_t height = 0;
    uint8_t *source = nullptr;
    size_t source_size = 0;
    char *pixel_storage = nullptr;
    static constexpr unsigned max_attempts = 4; // Initial + 3 retries.
    bool decoded = false;
    for (unsigned attempt = 1; attempt <= max_attempts; ++attempt)
    {
        std::printf("Retrieving picture (attempt %u/%u): %s\n",
                    attempt, max_attempts, pic_url.c_str());
        http_response_t response;
        const bool retrieved = http_get(request_url, response);
        if (retrieved && response.data != nullptr && response.length != 0)
        {
            const char *response_start = response.data;
            while (*response_start == ' ' || *response_start == '\r' ||
                   *response_start == '\n' || *response_start == '\t')
                ++response_start;
            if (std::strncmp(response_start, "null", 4) == 0)
            {
                std::free(response.data);
                return false;
            }

            width = 0;
            height = 0;
            source = nullptr;
            source_size = 0;
            decoded = parse_picture_json_in_place(
                response.data, response.length, width, height, source,
                source_size);
            if (decoded)
            {
                pixel_storage = response.data;
                response.data = nullptr;
            }
        }
        std::free(response.data);

        if (decoded)
            break;

        if (attempt < max_attempts)
        {
            std::printf("Picture retrieval or decoding failed; retrying %s\n",
                        pic_url.c_str());
            vTaskDelay(pdMS_TO_TICKS(250));
        }
    }
    if (!decoded)
    {
        report_error("Unable to retrieve or decode picture after 3 retries: %s",
                     pic_url.c_str());
        return false;
    }
    std::printf("Decoded picture: %ux%u pixels\n",
                static_cast<unsigned>(width),
                static_cast<unsigned>(height));
    const size_t source_bytes_per_row = (width + 7) / 8;

    static constexpr size_t destination_bytes_per_row =
        (PRINTER_PIXEL_WIDTH + 7) / 8;
    const uint8_t *raster_data = source;
    size_t raster_size = source_size;
    uint8_t *centered = nullptr;
    if (width < PRINTER_PIXEL_WIDTH)
    {
        const size_t centered_size = destination_bytes_per_row * height;
        centered = static_cast<uint8_t *>(std::calloc(centered_size, 1));
        if (centered == nullptr)
        {
            report_error("Not enough memory to center picture: %s",
                         pic_url.c_str());
            std::free(pixel_storage);
            return false;
        }
        const size_t left_padding = (PRINTER_PIXEL_WIDTH - width) / 2;

        for (size_t y = 0; y < height; ++y)
        {
            for (size_t x = 0; x < width; ++x)
            {
                const uint8_t source_mask = 0x80 >> (x & 7);
                if ((source[y * source_bytes_per_row + x / 8] &
                     source_mask) != 0)
                {
                    const size_t destination_x = left_padding + x;
                    centered[y * destination_bytes_per_row +
                             destination_x / 8] |=
                        0x80 >> (destination_x & 7);
                }
            }
        }
        raster_data = centered;
        raster_size = centered_size;
    }

    const uint8_t raster_header[] = {
        0x1d, 0x76, 0x30, 0x00, // GS v 0, normal raster
        static_cast<uint8_t>(destination_bytes_per_row & 0xff),
        static_cast<uint8_t>((destination_bytes_per_row >> 8) & 0xff),
        static_cast<uint8_t>(height & 0xff),
        static_cast<uint8_t>((height >> 8) & 0xff),
    };
    const bool print_succeeded =
        printer_write(reinterpret_cast<const char *>(raster_header),
                      sizeof(raster_header)) &&
        printer_write(reinterpret_cast<const char *>(raster_data),
                      raster_size) &&
        printer_write("\r\n", 2);
    std::free(centered);
    std::free(pixel_storage);
    if (!print_succeeded)
    {
        report_error("Unable to send picture to printer: %s",
                     pic_url.c_str());
        return false;
    }
    std::printf("Printed picture: %s\n", pic_url.c_str());
    return true;
}

static void append_wrapped_printer_line(std::string &document,
                                        const std::string &text,
                                        size_t printer_width = 48)
{
    size_t paragraph_start = 0;

    while (paragraph_start <= text.size())
    {
        const size_t newline = text.find('\n', paragraph_start);
        const size_t paragraph_end =
            newline == std::string::npos ? text.size() : newline;
        size_t word_start = paragraph_start;
        std::string line;

        while (word_start < paragraph_end)
        {
            while (word_start < paragraph_end &&
                   (text[word_start] == ' ' || text[word_start] == '\t' ||
                    text[word_start] == '\r'))
            {
                ++word_start;
            }
            if (word_start >= paragraph_end)
            {
                break;
            }

            size_t word_end = word_start;
            while (word_end < paragraph_end && text[word_end] != ' ' &&
                   text[word_end] != '\t' && text[word_end] != '\r')
            {
                ++word_end;
            }

            std::string word = text.substr(word_start, word_end - word_start);
            word_start = word_end;

            if (!line.empty() && line.size() + 1 + word.size() > printer_width)
            {
                document += line;
                document += "\r\n";
                line.clear();
            }

            while (word.size() > printer_width)
            {
                if (!line.empty())
                {
                    document += line;
                    document += "\r\n";
                    line.clear();
                }
                document.append(word, 0, printer_width);
                document += "\r\n";
                word.erase(0, printer_width);
            }

            if (!word.empty())
            {
                if (!line.empty())
                {
                    line += ' ';
                }
                line += word;
            }
        }

        document += line;
        document += "\r\n";

        if (newline == std::string::npos)
        {
            break;
        }
        paragraph_start = newline + 1;
    }
}

static void print_content_to_printer()
{
    std::string document("\x1b\x40", 2);
    document.append("\x1b\x74", 2); // ESC t: select character table
    document.push_back(static_cast<char>(PRINTER_CODE_PAGE));
    bool print_failed = false;

    const auto flush_document = [&document, &print_failed]()
    {
        if (document.empty())
            return true;
        if (!printer_write(document))
        {
            print_failed = true;
            return false;
        }

        // Release the segment's capacity so it is not retained while the
        // next image response and raster buffers are allocated.
        std::string empty;
        document.swap(empty);
        return true;
    };

    const auto write_pair = [&document](const char *key,
                                        const std::string &value)
    {
        (void)key;
        append_wrapped_printer_line(
            document, printer_encode_windows_1252(value));
    };
    const auto write_double_height_pair = [&document](
                                              const char *key,
                                              const std::string &value)
    {
        const std::string encoded_value =
            printer_encode_windows_1252(value);
        const bool use_double_width = encoded_value.size() < 24;
        document.append("\x1b\x61\x01", 3); // ESC a 1: center
        if (use_double_width)
        {
            // GS ! 0x11: double width and double height.
            document.append("\x1d\x21\x11", 3);
        }
        else
        {
            // GS ! 0x01: double height only.
            document.append("\x1d\x21\x01", 3);
        }
        (void)key;
        append_wrapped_printer_line(document, encoded_value,
                                    use_double_width ? 24 : 48);
        document.append("\x1d\x21\x00", 3); // GS ! 0: normal size
        document.append("\x1b\x61\x00", 3); // ESC a 0: left align
    };
    const auto write_centered_double_height = [&document](
                                                   const std::string &value)
    {
        document.append("\x1b\x61\x01", 3); // ESC a 1: center
        document.append("\x1d\x21\x01", 3); // GS ! 1: double height
        append_wrapped_printer_line(
            document, printer_encode_windows_1252(value), 48);
        document.append("\x1d\x21\x00", 3); // GS ! 0: normal size
        document.append("\x1b\x61\x00", 3); // ESC a 0: left align
    };
    const auto write_centered_single_height = [&document](
                                                   const std::string &value)
    {
        document.append("\x1b\x61\x01", 3); // ESC a 1: center
        document.append("\x1d\x21\x00", 3); // GS ! 0: normal size
        append_wrapped_printer_line(
            document, printer_encode_windows_1252(value), 48);
        document.append("\x1b\x61\x00", 3); // ESC a 0: left align
    };

    for (const ContentItem &item : Content)
    {
        if (print_failed)
            break;

        const bool printable = std::visit([](const auto &content_item)
        {
            using ItemType = std::decay_t<decltype(content_item)>;
            if constexpr (std::is_same_v<ItemType, banner>)
            {
                return has_relative_bmp_url(content_item.content_value) ||
                       is_safe_printer_text(content_item.content_value);
            }
            return true;
        }, item);
        if (!printable)
            continue;

        std::visit([&document, &write_pair, &write_double_height_pair,
                    &write_centered_double_height,
                    &write_centered_single_height, &flush_document](
                       const auto &content_item)
                   {
            using ItemType = std::decay_t<decltype(content_item)>;

            if constexpr (std::is_same_v<ItemType, banner>) {
                if (has_relative_bmp_url(content_item.content_value))
                {
                    if (!flush_document())
                        return;
                    print_pic_to_printer(content_item.content_value);
                }
                else
                {
                    write_centered_double_height(content_item.content_value);
                }
                document.append("\x1b\x4a\x06", 3); // ESC J 6: feed 6 dots
            } else if constexpr (std::is_same_v<ItemType, divider>) {
                // ESC J 12: add a half-line (12-dot) margin above.
                document.append("\x1b\x4a\x0c", 3);
                if (has_relative_bmp_url(content_item.content_value))
                {
                    if (!flush_document())
                        return;
                    print_pic_to_printer(content_item.content_value);
                }
                else if (is_safe_printer_text(content_item.content_value))
                {
                    write_centered_single_height(content_item.content_value);
                }
                else
                {
                    document.append(CONTENT_DIVIDER,
                                    sizeof(CONTENT_DIVIDER) - 1);
                }
                // Match the top margin without adding a full blank line.
                document.append("\x1b\x4a\x0c", 3); // ESC J 12
            } else if constexpr (std::is_same_v<ItemType, article>) {
                write_double_height_pair(
                    "headline", content_item.content_value.headline);
                if (!flush_document())
                    return;
                if (print_pic_to_printer(content_item.content_value.pic))
                {
                    document.append("\x1b\x4a\x06", 3); // Feed 6 dots
                }
                write_pair("text", content_item.content_value.text);
            } else {
                write_pair("content-value", content_item.content_value);
            } }, item);
    }

    document += "\r\n\r\n\r\n";
    document.append("\x1d\x56\x00", 3); // GS V 0: full cut

    if (print_failed || !flush_document())
    {
        report_error("Unable to print API content");
        return;
    }

    uint8_t port_status = 0;
    if (!usb_printer_get_port_status(port_status))
    {
        report_error("Unable to get printer port status");
    }
    else if (!post_printer_status(port_status))
    {
        std::printf("Error\nUnable to send printer status to ping API\n");
    }
}

static esp_err_t http_event_handler(esp_http_client_event_t *event)
{
    if (event->event_id != HTTP_EVENT_ON_DATA || event->data_len == 0)
    {
        return ESP_OK;
    }

    auto *response = static_cast<http_response_t *>(event->user_data);
    const size_t new_length = response->length + event->data_len;
    auto *new_data = static_cast<char *>(heap_caps_realloc(
        response->data, new_length + 1, MALLOC_CAP_8BIT));
    if (new_data == nullptr)
    {
        return ESP_ERR_NO_MEM;
    }

    response->data = new_data;
    std::memcpy(response->data + response->length, event->data,
                event->data_len);
    response->length = new_length;
    response->data[response->length] = '\0';
    return ESP_OK;
}

static void fetch_content()
{
    http_response_t response;
    static constexpr char content_api_url[] =
        BASE_DOMAIN ":" BASE_PORT CONTENT_API_ROUTE;
    const bool is_https =
        std::strncmp(content_api_url, "https://", 8) == 0;

    esp_http_client_config_t config = {};
    config.url = content_api_url;
    config.method = HTTP_METHOD_GET;
    config.event_handler = http_event_handler;
    config.user_data = &response;
    config.timeout_ms = 10000;
    config.crt_bundle_attach = is_https ? esp_crt_bundle_attach : nullptr;

    esp_http_client_handle_t client = esp_http_client_init(&config);
    if (client == nullptr)
    {
        report_error("Unable to initialize content HTTP client");
        return;
    }

    const esp_err_t result = esp_http_client_perform(client);
    const int status_code = result == ESP_OK
                                ? esp_http_client_get_status_code(client)
                                : 0;
    esp_http_client_cleanup(client);
    client = nullptr;

    if (result != ESP_OK || status_code < 200 || status_code >= 300)
    {
        if (response.data != nullptr)
        {
            report_error("Content API error: %.180s", response.data);
        }
        else if (result != ESP_OK)
        {
            report_error("Content API request failed: %s",
                         esp_err_to_name(result));
        }
        else
        {
            report_error("Content API returned HTTP status %d", status_code);
        }
    }
    else if (parse_content(response))
    {
        // Parsing copies all retained values into Content. Release the HTTP
        // response before downloading and decoding pictures so internal RAM
        // can be reused for their JSON and raster buffers.
        std::free(response.data);
        response.data = nullptr;
        response.length = 0;
        print_content();
        std::fflush(stdout);
        print_content_to_printer();
    }

    std::fflush(stdout);
    std::free(response.data);
}

static void button_task(void *argument)
{
    while (true)
    {
        ulTaskNotifyTake(pdTRUE, portMAX_DELAY);

        // Debounce the initial edge before beginning the hold timer.
        vTaskDelay(pdMS_TO_TICKS(30));
        ulTaskNotifyTake(pdTRUE, 0);

        if (gpio_get_level(ARCADE_BUTTON_PIN) != 0)
        {
            continue;
        }

        const TickType_t hold_start = xTaskGetTickCount();
        const TickType_t required_hold = pdMS_TO_TICKS(500);
        bool released = false;

        while (xTaskGetTickCount() - hold_start < required_hold)
        {
            const TickType_t elapsed = xTaskGetTickCount() - hold_start;
            const TickType_t remaining = required_hold - elapsed;

            if (ulTaskNotifyTake(pdTRUE, remaining) > 0)
            {
                vTaskDelay(pdMS_TO_TICKS(30));
                ulTaskNotifyTake(pdTRUE, 0);

                if (gpio_get_level(ARCADE_BUTTON_PIN) != 0)
                {
                    released = true;
                    break;
                }
            }
        }

        if (!released && gpio_get_level(ARCADE_BUTTON_PIN) == 0)
        {
            led_cycle_enabled.store(false);
            vTaskDelay(pdMS_TO_TICKS(10));
            ESP_ERROR_CHECK(ledc_set_duty(LEDC_LOW_SPEED_MODE,
                                          LEDC_CHANNEL_0, 255));
            ESP_ERROR_CHECK(ledc_update_duty(LEDC_LOW_SPEED_MODE,
                                             LEDC_CHANNEL_0));

            fetch_content();
            vTaskDelay(pdMS_TO_TICKS(REFRESH_SECONDS * 1000));

            // Ignore any button edges that occurred while fetching or waiting.
            ulTaskNotifyTake(pdTRUE, 0);
            led_cycle_enabled.store(true);
        }
    }
}

static void arcade_led_task(void *argument)
{
    int brightness = 0;
    int direction = 1;
    bool was_cycling = true;

    while (true)
    {
        if (!led_cycle_enabled.load())
        {
            was_cycling = false;
            vTaskDelay(pdMS_TO_TICKS(10));
            continue;
        }

        if (!was_cycling)
        {
            brightness = 255;
            direction = -1;
            was_cycling = true;
        }

        ESP_ERROR_CHECK(ledc_set_duty(LEDC_LOW_SPEED_MODE,
                                      LEDC_CHANNEL_0, brightness));
        ESP_ERROR_CHECK(ledc_update_duty(LEDC_LOW_SPEED_MODE,
                                         LEDC_CHANNEL_0));

        brightness += direction;
        if (brightness >= 255)
        {
            brightness = 255;
            direction = -1;
        }
        else if (brightness <= 0)
        {
            brightness = 0;
            direction = 1;
        }

        vTaskDelay(pdMS_TO_TICKS(10));
    }
}

static void wifi_event_handler(void *arg, esp_event_base_t event_base,
                               int32_t event_id, void *event_data)
{
    if (event_base == WIFI_EVENT && event_id == WIFI_EVENT_STA_START)
    {
        ESP_ERROR_CHECK(esp_wifi_connect());
    }
    else if (event_base == WIFI_EVENT &&
             event_id == WIFI_EVENT_STA_DISCONNECTED)
    {
        wifi_connected.store(false);
        ESP_LOGW(TAG, "Disconnected; reconnecting to %s", WIFI_SSID);
        ESP_ERROR_CHECK(esp_wifi_connect());
    }
    else if (event_base == IP_EVENT && event_id == IP_EVENT_STA_GOT_IP)
    {
        wifi_connected.store(true);
        const auto *event = static_cast<const ip_event_got_ip_t *>(event_data);
        ESP_LOGI(TAG, "Connected to %s with IP " IPSTR,
                 WIFI_SSID, IP2STR(&event->ip_info.ip));
        std::printf("The Italian explorer has reached the new world\n");
        std::fflush(stdout);

        if (!startup_report_sent)
        {
            startup_report_sent = true;
            if (retained_failure.signature == RETAINED_FAILURE_SIGNATURE)
            {
                char message[192];
                std::snprintf(
                    message, sizeof(message),
                    "Recovered after allocation failure: %u bytes, caps "
                    "0x%08X, function %s",
                    static_cast<unsigned>(retained_failure.allocation_size),
                    static_cast<unsigned>(retained_failure.allocation_caps),
                    retained_failure.allocation_function);
                if (post_ping_message(message))
                    retained_failure.signature = 0;
            }

            const char *reset_message = nullptr;
            switch (boot_reset_reason)
            {
            case ESP_RST_PANIC:
                reset_message = "Device recovered from a panic reboot";
                break;
            case ESP_RST_INT_WDT:
                reset_message =
                    "Device recovered from an interrupt watchdog reboot";
                break;
            case ESP_RST_TASK_WDT:
                reset_message =
                    "Device recovered from a task watchdog reboot";
                break;
            case ESP_RST_WDT:
                reset_message = "Device recovered from a watchdog reboot";
                break;
            case ESP_RST_BROWNOUT:
                reset_message = "Device recovered from a brownout reboot";
                break;
            case ESP_RST_CPU_LOCKUP:
                reset_message = "Device recovered from a CPU lockup reboot";
                break;
            default:
                break;
            }
            if (reset_message != nullptr)
                post_ping_message(reset_message);
        }

        if (content_request_task_handle == nullptr)
        {
            const BaseType_t created =
                xTaskCreate(button_task, "button", 6144, nullptr,
                            5, &content_request_task_handle);
            if (created != pdPASS)
            {
                report_error("Unable to create HTTP request task");
            }
        }
    }
}

extern "C" void app_main()
{
    boot_reset_reason = esp_reset_reason();
    if (boot_reset_reason == ESP_RST_POWERON)
        retained_failure.signature = 0;
    heap_caps_register_failed_alloc_callback(allocation_failed_hook);

    esp_err_t result = nvs_flash_init();
    if (result == ESP_ERR_NVS_NO_FREE_PAGES ||
        result == ESP_ERR_NVS_NEW_VERSION_FOUND)
    {
        ESP_ERROR_CHECK(nvs_flash_erase());
        ESP_ERROR_CHECK(nvs_flash_init());
    }
    else
    {
        ESP_ERROR_CHECK(result);
    }

    gpio_config_t button_config = {};
    button_config.pin_bit_mask = 1ULL << ARCADE_BUTTON_PIN;
    button_config.mode = GPIO_MODE_INPUT;
    button_config.pull_up_en = GPIO_PULLUP_ENABLE;
    button_config.pull_down_en = GPIO_PULLDOWN_DISABLE;
    button_config.intr_type = GPIO_INTR_ANYEDGE;
    ESP_ERROR_CHECK(gpio_config(&button_config));
    ESP_ERROR_CHECK(gpio_install_isr_service(0));
    ESP_ERROR_CHECK(gpio_isr_handler_add(ARCADE_BUTTON_PIN,
                                         arcade_button_isr, nullptr));

    ledc_timer_config_t led_timer = {};
    led_timer.speed_mode = LEDC_LOW_SPEED_MODE;
    led_timer.duty_resolution = LEDC_TIMER_8_BIT;
    led_timer.timer_num = LEDC_TIMER_0;
    led_timer.freq_hz = 5000;
    led_timer.clk_cfg = LEDC_AUTO_CLK;
    ESP_ERROR_CHECK(ledc_timer_config(&led_timer));

    ledc_channel_config_t led_channel = {};
    led_channel.gpio_num = ARCADE_LED_PIN;
    led_channel.speed_mode = LEDC_LOW_SPEED_MODE;
    led_channel.channel = LEDC_CHANNEL_0;
    led_channel.timer_sel = LEDC_TIMER_0;
    led_channel.duty = 0;
    led_channel.hpoint = 0;
    ESP_ERROR_CHECK(ledc_channel_config(&led_channel));

    ESP_ERROR_CHECK(usb_printer_init() ? ESP_OK : ESP_FAIL);

    ESP_ERROR_CHECK(xTaskCreate(arcade_led_task, "arcade_led", 2048, nullptr,
                                4, nullptr) == pdPASS
                        ? ESP_OK
                        : ESP_ERR_NO_MEM);

    ESP_ERROR_CHECK(esp_netif_init());
    ESP_ERROR_CHECK(esp_event_loop_create_default());
    esp_netif_t *wifi_netif = esp_netif_create_default_wifi_sta();
    if (wifi_netif == nullptr)
    {
        ESP_LOGE(TAG, "Failed to create the Wi-Fi network interface");
        return;
    }
    ESP_ERROR_CHECK(esp_netif_set_hostname(wifi_netif, DEVICE_HOSTNAME));

    wifi_init_config_t init_config = WIFI_INIT_CONFIG_DEFAULT();
    ESP_ERROR_CHECK(esp_wifi_init(&init_config));

    ESP_ERROR_CHECK(esp_event_handler_register(
        WIFI_EVENT, ESP_EVENT_ANY_ID, wifi_event_handler, nullptr));
    ESP_ERROR_CHECK(esp_event_handler_register(
        IP_EVENT, IP_EVENT_STA_GOT_IP, wifi_event_handler, nullptr));

    wifi_config_t wifi_config = {};
    std::snprintf(reinterpret_cast<char *>(wifi_config.sta.ssid),
                  sizeof(wifi_config.sta.ssid), "%s", WIFI_SSID);
    std::snprintf(reinterpret_cast<char *>(wifi_config.sta.password),
                  sizeof(wifi_config.sta.password), "%s", WIFI_PASSWORD);
    wifi_config.sta.threshold.authmode = WIFI_AUTH_WPA2_PSK;

    ESP_ERROR_CHECK(esp_wifi_set_mode(WIFI_MODE_STA));
    ESP_ERROR_CHECK(esp_wifi_set_config(WIFI_IF_STA, &wifi_config));
    ESP_ERROR_CHECK(esp_wifi_start());

    ESP_LOGI(TAG, "%s is connecting to %s", DEVICE_HOSTNAME, WIFI_SSID);
}
