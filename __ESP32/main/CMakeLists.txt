idf_component_register(SRCS "__ESP32.cpp" "usb_printer.cpp" "printer_encoding.cpp"
                    INCLUDE_DIRS "."
                    REQUIRES esp_driver_gpio esp_driver_ledc esp_event esp_http_client esp_netif esp_wifi espressif__cjson espressif__usb mbedtls nvs_flash)
