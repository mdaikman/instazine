#ifndef INSTAZINE_H
#define INSTAZINE_H

#include <string>
#include <utility>

class banner {
public:
    std::string content_type = "banner";
    std::string content_value;

    banner() = default;

    explicit banner(std::string value)
        : content_value(std::move(value))
    {
    }
};

class textline {
public:
    std::string content_type = "textline";
    std::string content_value;

    textline() = default;

    explicit textline(std::string value)
        : content_value(std::move(value))
    {
    }
};

class article_content {
public:
    std::string headline;
    std::string pic;
    std::string text;

    article_content() = default;

    article_content(std::string headline_value,
                    std::string pic_value,
                    std::string text_value)
        : headline(std::move(headline_value)),
          pic(std::move(pic_value)),
          text(std::move(text_value))
    {
    }
};

class article {
public:
    std::string content_type = "article";
    article_content content_value;

    article() = default;

    explicit article(article_content value)
        : content_value(std::move(value))
    {
    }
};

#endif /* INSTAZINE_H */
