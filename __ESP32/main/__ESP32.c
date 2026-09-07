#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "esp_crt_bundle.h"
#include "esp_event.h"
#include "esp_http_client.h"
#include "esp_log.h"
#include "esp_netif.h"
#include "esp_wifi.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "cJSON.h"
#include "nvs_flash.h"
#include "settings.h"

static const char *TAG = "wifi_client";
static TaskHandle_t content_request_task_handle;

typedef struct
{
    char *data;
    size_t length;
} http_response_t;

static void print_json_values(const cJSON *item, const char *parent_key)
{
    const char *key = item->string != NULL ? item->string : parent_key;

    if (cJSON_IsObject(item) || cJSON_IsArray(item))
    {
        const cJSON *child;
        cJSON_ArrayForEach(child, item)
        {
            print_json_values(child, key);
        }
    }
    else if (cJSON_IsString(item))
    {
        printf("%s: %s\n", key != NULL ? key : "value", item->valuestring);
    }
    else if (cJSON_IsNumber(item))
    {
        printf("%s: %.17g\n", key != NULL ? key : "value",
               item->valuedouble);
    }
    else if (cJSON_IsBool(item))
    {
        printf("%s: %s\n", key != NULL ? key : "value",
               cJSON_IsTrue(item) ? "true" : "false");
    }
    else if (cJSON_IsNull(item))
    {
        printf("%s: null\n", key != NULL ? key : "value");
    }
}

static void parse_and_print_json(const http_response_t *response)
{
    if (response->data == NULL || response->length == 0)
    {
        printf("Error\nEmpty JSON response\n");
        return;
    }

    cJSON *root = cJSON_ParseWithLength(response->data, response->length);
    if (root == NULL)
    {
        const char *error_position = cJSON_GetErrorPtr();
        printf("Error\nInvalid JSON");
        if (error_position != NULL)
        {
            printf(" at byte %td", error_position - response->data);
        }
        printf("\n");
        return;
    }

    print_json_values(root, NULL);
    cJSON_Delete(root);
}

static esp_err_t http_event_handler(esp_http_client_event_t *event)
{
    if (event->event_id != HTTP_EVENT_ON_DATA || event->data_len == 0)
    {
        return ESP_OK;
    }

    http_response_t *response = event->user_data;
    size_t new_length = response->length + event->data_len;
    char *new_data = realloc(response->data, new_length + 1);
    if (new_data == NULL)
    {
        return ESP_ERR_NO_MEM;
    }

    response->data = new_data;
    memcpy(response->data + response->length, event->data, event->data_len);
    response->length = new_length;
    response->data[response->length] = '\0';
    return ESP_OK;
}

static void fetch_content_task(void *argument)
{
    http_response_t response = {0};
    const bool is_https = strncmp(CONTENT_API_URL, "https://", 8) == 0;
    esp_http_client_config_t config = {
        .url = CONTENT_API_URL,
        .method = HTTP_METHOD_GET,
        .event_handler = http_event_handler,
        .user_data = &response,
        .timeout_ms = 10000,
        .crt_bundle_attach = is_https ? esp_crt_bundle_attach : NULL,
    };

    esp_http_client_handle_t client = esp_http_client_init(&config);
    if (client == NULL)
    {
        printf("Error\nUnable to initialize HTTP client\n");
        goto finished;
    }

    esp_err_t result = esp_http_client_perform(client);
    int status_code = result == ESP_OK
                          ? esp_http_client_get_status_code(client)
                          : 0;

    if (result != ESP_OK || status_code < 200 || status_code >= 300)
    {
        printf("Error\n");
        if (response.data != NULL)
        {
            printf("%s\n", response.data);
        }
        else if (result != ESP_OK)
        {
            printf("%s\n", esp_err_to_name(result));
        }
        else
        {
            printf("HTTP status %d\n", status_code);
        }
    }
    else
    {
        parse_and_print_json(&response);
    }
    fflush(stdout);
    esp_http_client_cleanup(client);

finished:
    free(response.data);
    content_request_task_handle = NULL;
    vTaskDelete(NULL);
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
        const ip_event_got_ip_t *event = event_data;
        ESP_LOGI(TAG, "Connected to %s with IP " IPSTR,
                 WIFI_SSID, IP2STR(&event->ip_info.ip));
        printf(OK_PHRASE + "\n");
        fflush(stdout);

        if (content_request_task_handle == NULL)
        {
            BaseType_t created = xTaskCreate(fetch_content_task,
                                             "fetch_content", 6144, NULL, 5,
                                             &content_request_task_handle);
            if (created != pdPASS)
            {
                printf("Error\nUnable to create HTTP request task\n");
                fflush(stdout);
            }
        }
    }
}

void app_main(void)
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

    ESP_ERROR_CHECK(esp_netif_init());
    ESP_ERROR_CHECK(esp_event_loop_create_default());
    esp_netif_t *wifi_netif = esp_netif_create_default_wifi_sta();
    if (wifi_netif == NULL)
    {
        ESP_LOGE(TAG, "Failed to create the Wi-Fi network interface");
        return;
    }
    ESP_ERROR_CHECK(esp_netif_set_hostname(wifi_netif, DEVICE_HOSTNAME));

    wifi_init_config_t init_config = WIFI_INIT_CONFIG_DEFAULT();
    ESP_ERROR_CHECK(esp_wifi_init(&init_config));

    ESP_ERROR_CHECK(esp_event_handler_register(
        WIFI_EVENT, ESP_EVENT_ANY_ID, wifi_event_handler, NULL));
    ESP_ERROR_CHECK(esp_event_handler_register(
        IP_EVENT, IP_EVENT_STA_GOT_IP, wifi_event_handler, NULL));

    wifi_config_t wifi_config = {
        .sta = {
            .ssid = WIFI_SSID,
            .password = WIFI_PASSWORD,
            .threshold.authmode = WIFI_AUTH_WPA2_PSK,
        },
    };

    ESP_ERROR_CHECK(esp_wifi_set_mode(WIFI_MODE_STA));
    ESP_ERROR_CHECK(esp_wifi_set_config(WIFI_IF_STA, &wifi_config));
    ESP_ERROR_CHECK(esp_wifi_start());

    ESP_LOGI(TAG, "%s is connecting to %s", DEVICE_HOSTNAME, WIFI_SSID);
}
