idf_component_register(SRCS "__ESP32.cpp"
                    INCLUDE_DIRS "."
                    REQUIRES esp_driver_gpio esp_event esp_http_client esp_netif esp_wifi espressif__cjson mbedtls nvs_flash)
