#include "printer_encoding.h"

#include <cstdint>

namespace
{
uint8_t windows_1252_byte(uint32_t codepoint)
{
    if (codepoint <= 0x7f || (codepoint >= 0xa0 && codepoint <= 0xff))
    {
        return static_cast<uint8_t>(codepoint);
    }

    struct mapping_t
    {
        uint32_t codepoint;
        uint8_t byte;
    };
    static constexpr mapping_t mappings[] = {
        {0x20ac, 0x80}, {0x201a, 0x82}, {0x0192, 0x83}, {0x201e, 0x84},
        {0x2026, 0x85}, {0x2020, 0x86}, {0x2021, 0x87}, {0x02c6, 0x88},
        {0x2030, 0x89}, {0x0160, 0x8a}, {0x2039, 0x8b}, {0x0152, 0x8c},
        {0x017d, 0x8e}, {0x2018, 0x91}, {0x2019, 0x92}, {0x201c, 0x93},
        {0x201d, 0x94}, {0x2022, 0x95}, {0x2013, 0x96}, {0x2014, 0x97},
        {0x02dc, 0x98}, {0x2122, 0x99}, {0x0161, 0x9a}, {0x203a, 0x9b},
        {0x0153, 0x9c}, {0x017e, 0x9e}, {0x0178, 0x9f},
    };

    for (const mapping_t &mapping : mappings)
    {
        if (mapping.codepoint == codepoint)
        {
            return mapping.byte;
        }
    }
    return '?';
}
} // namespace

std::string printer_encode_windows_1252(const std::string &utf8)
{
    std::string encoded;
    encoded.reserve(utf8.size());

    for (std::size_t index = 0; index < utf8.size();)
    {
        const uint8_t first = static_cast<uint8_t>(utf8[index]);
        uint32_t codepoint = 0;
        std::size_t length = 0;

        if (first < 0x80)
        {
            codepoint = first;
            length = 1;
        }
        else if ((first & 0xe0) == 0xc0)
        {
            codepoint = first & 0x1f;
            length = 2;
        }
        else if ((first & 0xf0) == 0xe0)
        {
            codepoint = first & 0x0f;
            length = 3;
        }
        else if ((first & 0xf8) == 0xf0)
        {
            codepoint = first & 0x07;
            length = 4;
        }
        else
        {
            encoded += '?';
            ++index;
            continue;
        }

        if (index + length > utf8.size())
        {
            encoded += '?';
            break;
        }

        bool valid = true;
        for (std::size_t continuation = 1; continuation < length;
             ++continuation)
        {
            const uint8_t byte =
                static_cast<uint8_t>(utf8[index + continuation]);
            if ((byte & 0xc0) != 0x80)
            {
                valid = false;
                break;
            }
            codepoint = (codepoint << 6) | (byte & 0x3f);
        }

        if (!valid)
        {
            encoded += '?';
            ++index;
            continue;
        }

        encoded += static_cast<char>(windows_1252_byte(codepoint));
        index += length;
    }

    return encoded;
}
