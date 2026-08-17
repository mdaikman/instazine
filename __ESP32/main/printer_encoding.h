#ifndef INSTAZINE_PRINTER_ENCODING_H
#define INSTAZINE_PRINTER_ENCODING_H

#include <string>

// The content API returns UTF-8, while the printer expects one-byte text in
// the selected ESC/POS character table.
std::string printer_encode_windows_1252(const std::string &utf8);

#endif /* INSTAZINE_PRINTER_ENCODING_H */
