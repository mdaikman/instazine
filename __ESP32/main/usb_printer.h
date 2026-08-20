#ifndef INSTAZINE_USB_PRINTER_H
#define INSTAZINE_USB_PRINTER_H

#include <cstddef>
#include <cstdint>

bool usb_printer_init();
bool usb_printer_is_ready();
bool usb_printer_write(const char *data, std::size_t length);
bool usb_printer_get_port_status(uint8_t &status);

#endif /* INSTAZINE_USB_PRINTER_H */
