#ifndef INSTAZINE_SETTINGS_H
#define INSTAZINE_SETTINGS_H

// Copy this file to settings.h and fill in values for your local environment.
#define WIFI_SSID "your-wifi-name"
#define WIFI_PASSWORD "your-wifi-password"
#define API_TOKEN "your-board-api-token"
#define DEVICE_HOSTNAME "instazine"
#define BASE_DOMAIN "http://your-server"
#define BASE_PORT "8000"
#define CONTENT_API_ROUTE "/stories/api/content"
#define ASSET_API_ROUTE "/stories/api/assets"
#define PIC_API_ROUTE "/stories/api/pic"
#define PING_API_ROUTE "/stories/api/ping"
#define OK_PHRASE "The Italian explorer has reached the new world"
#define PRINTER_USB_VENDOR_ID 0x28E9
#define PRINTER_USB_PRODUCT_ID 0x0289
#define PRINTER_CODE_PAGE 16 // Common ESC/POS value for Windows-1252
#define PRINTER_PIXEL_WIDTH 576
#define REFRESH_SECONDS 5 // Cooldown after a print before the button works again

#endif /* INSTAZINE_SETTINGS_H */
