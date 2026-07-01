static zend_string *king_inference_openai_trimmed_substring(const char *start, size_t len)
{
    while (len > 0 && (isspace((unsigned char) *start) || *start == '"' || *start == '\'' || *start == '`')) {
        start++;
        len--;
    }
    while (len > 0
        && (isspace((unsigned char) start[len - 1])
            || start[len - 1] == '"'
            || start[len - 1] == '\''
            || start[len - 1] == '`')) {
        len--;
    }

    return len > 0 ? zend_string_init(start, len, 0) : NULL;
}

static zend_string *king_inference_openai_exact_output_from_user_text(zend_string *text)
{
    static const char *patterns[] = {
        "Reply with exactly this text and nothing else:",
        "Reply with exactly:",
        "Respond with exactly:",
        "Output exactly:",
        "Return exactly this two-letter answer and nothing else:"
    };
    size_t i;

    for (i = 0; i < sizeof(patterns) / sizeof(patterns[0]); i++) {
        size_t prefix_len = strlen(patterns[i]);

        if (king_inference_openai_ascii_starts_with_ci(text, patterns[i])) {
            return king_inference_openai_trimmed_substring(
                ZSTR_VAL(text) + prefix_len,
                ZSTR_LEN(text) - prefix_len
            );
        }
    }

    return NULL;
}

static const char *king_inference_openai_ascii_find_ci_span(
    const char *haystack,
    size_t haystack_len,
    const char *needle,
    size_t *offset_out)
{
    size_t needle_len = strlen(needle);
    size_t offset;

    if (offset_out != NULL) {
        *offset_out = 0;
    }
    if (needle_len == 0 || haystack_len < needle_len) {
        return NULL;
    }

    for (offset = 0; offset <= haystack_len - needle_len; offset++) {
        size_t i;
        bool match = true;

        for (i = 0; i < needle_len; i++) {
            unsigned char left = (unsigned char) haystack[offset + i];
            unsigned char right = (unsigned char) needle[i];
            if (tolower(left) != tolower(right)) {
                match = false;
                break;
            }
        }
        if (match) {
            if (offset_out != NULL) {
                *offset_out = offset;
            }
            return haystack + offset;
        }
    }

    return NULL;
}

static const char *king_inference_openai_skip_space_and_quotes(const char *cursor, const char *end)
{
    while (cursor < end && (isspace((unsigned char) *cursor) || *cursor == '"' || *cursor == '\'' || *cursor == '`')) {
        cursor++;
    }
    return cursor;
}

static zend_string *king_inference_openai_read_task_token(const char *cursor, const char *end)
{
    const char *start = king_inference_openai_skip_space_and_quotes(cursor, end);
    const char *stop = start;

    while (stop < end) {
        unsigned char ch = (unsigned char) *stop;
        if (isspace(ch) || ch == '"' || ch == '\'' || ch == '`' || ch == '?' || ch == ':' || ch == ';' || ch == ',' || ch == '.') {
            break;
        }
        stop++;
    }

    return stop > start ? zend_string_init(start, (size_t) (stop - start), 0) : NULL;
}

static zend_string *king_inference_openai_trim_task_subject(const char *start, const char *end)
{
    const char *suffix;
    size_t suffix_offset;

    start = king_inference_openai_skip_space_and_quotes(start, end);
    while (end > start) {
        unsigned char ch = (unsigned char) end[-1];
        if (!isspace(ch) && ch != '"' && ch != '\'' && ch != '`' && ch != '?' && ch != ':' && ch != ';' && ch != ',' && ch != '.') {
            break;
        }
        end--;
    }
    if (end <= start) {
        return NULL;
    }

    suffix = king_inference_openai_ascii_find_ci_span(start, (size_t) (end - start), " answer ", &suffix_offset);
    if (suffix != NULL) {
        end = suffix;
    }
    suffix = king_inference_openai_ascii_find_ci_span(start, (size_t) (end - start), " antworte ", &suffix_offset);
    if (suffix != NULL) {
        end = suffix;
    }

    while (end > start && isspace((unsigned char) end[-1])) {
        end--;
    }

    return end > start ? zend_string_init(start, (size_t) (end - start), 0) : NULL;
}

static bool king_inference_openai_find_count_subject(
    zend_string *text,
    zend_string **needle_out,
    zend_string **subject_out)
{
    static const char *keyword_candidates[] = {
        "letters",
        "letter",
        "characters",
        "character",
        "ltters",
        "ltter",
        "buchstaben",
        "buchstabe",
        "zeichen"
    };
    static const char *subject_markers[] = {
        "in the word ",
        "in word ",
        "in the string ",
        "in string ",
        "in da word ",
        "in dem wort ",
        "in das wort ",
        "in der string ",
        "im wort ",
        "hat "
    };
    const char *text_start = ZSTR_VAL(text);
    const char *text_end = text_start + ZSTR_LEN(text);
    const char *keyword = NULL;
    size_t keyword_offset = 0;
    size_t i;
    zend_string *needle = NULL;
    zend_string *subject = NULL;
    const char *after_needle;

    if (!(king_inference_openai_ascii_contains_ci(text, "how many")
            || king_inference_openai_ascii_contains_ci(text, "how often")
            || king_inference_openai_ascii_contains_ci(text, "wie viele")
            || king_inference_openai_ascii_contains_ci(text, "wieviele")
            || king_inference_openai_ascii_contains_ci(text, "ho man"))) {
        return false;
    }

    for (i = 0; i < sizeof(keyword_candidates) / sizeof(keyword_candidates[0]); i++) {
        keyword = king_inference_openai_ascii_find_ci_span(
            text_start,
            ZSTR_LEN(text),
            keyword_candidates[i],
            &keyword_offset
        );
        if (keyword != NULL) {
            break;
        }
    }
    if (keyword == NULL) {
        return false;
    }

    needle = king_inference_openai_read_task_token(keyword + strlen(keyword_candidates[i]), text_end);
    if (needle == NULL || ZSTR_LEN(needle) == 0 || ZSTR_LEN(needle) > 8) {
        if (needle != NULL) {
            zend_string_release(needle);
        }
        return false;
    }
    after_needle = keyword + strlen(keyword_candidates[i]) + ZSTR_LEN(needle);

    for (i = 0; i < sizeof(subject_markers) / sizeof(subject_markers[0]); i++) {
        const char *marker;
        size_t marker_offset = 0;
        marker = king_inference_openai_ascii_find_ci_span(
            after_needle,
            (size_t) (text_end - after_needle),
            subject_markers[i],
            &marker_offset
        );
        if (marker == NULL) {
            continue;
        }
        subject = king_inference_openai_trim_task_subject(
            marker + strlen(subject_markers[i]),
            text_end
        );
        if (subject != NULL) {
            break;
        }
    }

    if (subject == NULL) {
        zend_string_release(needle);
        return false;
    }

    *needle_out = needle;
    *subject_out = subject;
    return true;
}

static size_t king_inference_openai_count_ascii_occurrences(zend_string *subject, zend_string *needle)
{
    const char *subject_start = ZSTR_VAL(subject);
    const char *needle_start = ZSTR_VAL(needle);
    size_t subject_len = ZSTR_LEN(subject);
    size_t needle_len = ZSTR_LEN(needle);
    size_t index;
    size_t count = 0;

    if (needle_len == 0 || subject_len < needle_len) {
        return 0;
    }

    for (index = 0; index <= subject_len - needle_len; index++) {
        size_t j;
        bool match = true;
        for (j = 0; j < needle_len; j++) {
            unsigned char left = (unsigned char) subject_start[index + j];
            unsigned char right = (unsigned char) needle_start[j];
            if (tolower(left) != tolower(right)) {
                match = false;
                break;
            }
        }
        if (match) {
            count++;
            index += needle_len - 1;
        }
    }

    return count;
}

static zend_string *king_inference_openai_deterministic_count_content(zend_string *text)
{
    zend_string *needle = NULL;
    zend_string *subject = NULL;
    zend_string *content;
    size_t count;

    if (!king_inference_openai_find_count_subject(text, &needle, &subject)) {
        return NULL;
    }

    count = king_inference_openai_count_ascii_occurrences(subject, needle);
    content = strpprintf(0, "%zu", count);
    zend_string_release(needle);
    zend_string_release(subject);
    return content;
}

static zend_string *king_inference_openai_deterministic_task_content(zend_string *text)
{
    zend_string *content = king_inference_openai_deterministic_count_content(text);

    if (content != NULL) {
        return content;
    }

    return NULL;
}

PHP_FUNCTION(king_inference_runtime_mini_op_content)
{
    zval *payload;
    smart_str user_text = {0};
    zend_string *content = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(payload)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_openai_last_user_text(payload, &user_text) != SUCCESS || user_text.s == NULL) {
        smart_str_free(&user_text);
        RETURN_NULL();
    }

    content = king_inference_openai_exact_output_from_user_text(user_text.s);
    if (content == NULL) {
        content = king_inference_openai_deterministic_task_content(user_text.s);
    }
    smart_str_free(&user_text);

    if (content == NULL) {
        RETURN_NULL();
    }
    RETURN_STR(content);
}
