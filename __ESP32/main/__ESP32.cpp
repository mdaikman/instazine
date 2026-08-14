#include <atomic>
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
#include "esp_event.h"
#include "driver/gpio.h"
#include "driver/ledc.h"
#include "esp_http_client.h"
#include "esp_log.h"
#include "esp_netif.h"
#include "esp_wifi.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "instazine.h"
#include "nvs_flash.h"
#include "settings.h"

using ContentItem = std::variant<banner, textline, article>;

std::vector<ContentItem> Content;

static const char *TAG = "wifi_client";
static constexpr gpio_num_t ARCADE_BUTTON_PIN = GPIO_NUM_4;
static constexpr gpio_num_t ARCADE_LED_PIN = GPIO_NUM_5;
static TaskHandle_t content_request_task_handle;
static std::atomic<bool> led_cycle_enabled{true};

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
        std::printf("Error\nEmpty JSON response\n");
        return false;
    }

    cJSON *root = cJSON_ParseWithLength(response.data, response.length);
    if (root == nullptr)
    {
        const char *error_position = cJSON_GetErrorPtr();
        std::printf("Error\nInvalid JSON");
        if (error_position != nullptr)
        {
            std::printf(" at byte %td", error_position - response.data);
        }
        std::printf("\n");
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
        std::printf("Error\nJSON does not contain content.items\n");
        cJSON_Delete(root);
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
            std::printf("Error\nContent item has no valid content-type\n");
            cJSON_Delete(root);
            return false;
        }

        if (std::strcmp(type->valuestring, "banner") == 0)
        {
            if (!cJSON_IsString(value) || value->valuestring == nullptr)
            {
                std::printf("Error\nBanner has no valid content-value\n");
                cJSON_Delete(root);
                return false;
            }
            parsed_content.emplace_back(banner(value->valuestring));
        }
        else if (std::strcmp(type->valuestring, "textline") == 0)
        {
            if (!cJSON_IsString(value) || value->valuestring == nullptr)
            {
                std::printf("Error\nTextline has no valid content-value\n");
                cJSON_Delete(root);
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
                std::printf("Error\nArticle has an invalid content-value\n");
                cJSON_Delete(root);
                return false;
            }
            parsed_content.emplace_back(article(std::move(article_value)));
        }
        else
        {
            std::printf("Error\nUnknown content-type: %s\n",
                        type->valuestring);
            cJSON_Delete(root);
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
            std::printf("-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-\n");
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

static esp_err_t http_event_handler(esp_http_client_event_t *event)
{
    if (event->event_id != HTTP_EVENT_ON_DATA || event->data_len == 0)
    {
        return ESP_OK;
    }

    auto *response = static_cast<http_response_t *>(event->user_data);
    const size_t new_length = response->length + event->data_len;
    auto *new_data =
        static_cast<char *>(std::realloc(response->data, new_length + 1));
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
    const bool is_https =
        std::strncmp(CONTENT_API_URL, "https://", 8) == 0;

    esp_http_client_config_t config = {};
    config.url = CONTENT_API_URL;
    config.method = HTTP_METHOD_GET;
    config.event_handler = http_event_handler;
    config.user_data = &response;
    config.timeout_ms = 10000;
    config.crt_bundle_attach = is_https ? esp_crt_bundle_attach : nullptr;

    esp_http_client_handle_t client = esp_http_client_init(&config);
    if (client == nullptr)
    {
        std::printf("Error\nUnable to initialize HTTP client\n");
        std::fflush(stdout);
        return;
    }

    const esp_err_t result = esp_http_client_perform(client);
    const int status_code = result == ESP_OK
                                ? esp_http_client_get_status_code(client)
                                : 0;

    if (result != ESP_OK || status_code < 200 || status_code >= 300)
    {
        std::printf("Error\n");
        if (response.data != nullptr)
        {
            std::printf("%s\n", response.data);
        }
        else if (result != ESP_OK)
        {
            std::printf("%s\n", esp_err_to_name(result));
        }
        else
        {
            std::printf("HTTP status %d\n", status_code);
        }
    }
    else if (parse_content(response))
    {
        print_content();
    }

    std::fflush(stdout);
    esp_http_client_cleanup(client);
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
        ESP_LOGW(TAG, "Disconnected; reconnecting to %s", WIFI_SSID);
        ESP_ERROR_CHECK(esp_wifi_connect());
    }
    else if (event_base == IP_EVENT && event_id == IP_EVENT_STA_GOT_IP)
    {
        const auto *event = static_cast<const ip_event_got_ip_t *>(event_data);
        ESP_LOGI(TAG, "Connected to %s with IP " IPSTR,
                 WIFI_SSID, IP2STR(&event->ip_info.ip));
        std::printf("The Italian explorer has reached the new world\n");
        std::fflush(stdout);

        if (content_request_task_handle == nullptr)
        {
            const BaseType_t created =
                xTaskCreate(button_task, "button", 6144, nullptr,
                            5, &content_request_task_handle);
            if (created != pdPASS)
            {
                std::printf("Error\nUnable to create HTTP request task\n");
                std::fflush(stdout);
            }
        }
    }
}

extern "C" void app_main()
{
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
