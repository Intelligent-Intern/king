function normalizeString(value) {
  return String(value || '').trim();
}

function translateMessage(translate, key, params = {}) {
  return typeof translate === 'function' ? translate(key, params) : key;
}

function localizedLabel(item = {}, translate) {
  const key = normalizeString(item.label_key);
  if (key !== '') return translateMessage(translate, key);
  return normalizeString(item.key);
}

function optionValues(field = {}) {
  if (!Array.isArray(field.options)) return null;
  return new Set(field.options.map((option) => normalizeString(option.value)).filter(Boolean));
}

function relationSelectionCount(relation = {}, relationSelections = {}) {
  const key = normalizeString(relation.key);
  const selected = Array.isArray(relationSelections?.[key]) ? relationSelections[key] : [];
  return selected.length;
}

function fieldValidation(field = {}) {
  return field.validation && typeof field.validation === 'object' ? field.validation : {};
}

function validationFailure(errorKey, message, details = {}) {
  return { ok: false, error_key: errorKey, message, ...details };
}

export function validateGovernanceCrudSubmission({
  fields = [],
  form = {},
  relationships = [],
  relationSelections = {},
  translate = null,
} = {}) {
  for (const field of fields) {
    const label = localizedLabel(field, translate);
    const value = normalizeString(form?.[field.key]);
    if (field?.required === true && value === '') {
      return validationFailure(
        'governance.field_required',
        translateMessage(translate, 'governance.field_required', { field: label }),
        { field_key: field.key },
      );
    }

    const values = optionValues(field);
    if (value !== '' && values && !values.has(value)) {
      return validationFailure(
        'governance.validation.option_invalid',
        translateMessage(translate, 'governance.validation.option_invalid', { field: label }),
        { field_key: field.key },
      );
    }

    const validation = fieldValidation(field);
    if (value !== '' && Number.isInteger(validation.max_length) && value.length > validation.max_length) {
      return validationFailure(
        'governance.validation.field_too_long',
        translateMessage(translate, 'governance.validation.field_too_long', { field: label, max: validation.max_length }),
        { field_key: field.key },
      );
    }
    if (value !== '' && normalizeString(validation.pattern) !== '') {
      const pattern = new RegExp(normalizeString(validation.pattern));
      if (!pattern.test(value)) {
        const errorKey = normalizeString(validation.pattern_error_key) || 'governance.validation.field_invalid';
        return validationFailure(
          errorKey,
          translateMessage(translate, errorKey, { field: label }),
          { field_key: field.key },
        );
      }
    }
    if (value !== '' && field?.input_type === 'datetime-local' && Number.isNaN(Date.parse(value))) {
      return validationFailure(
        'governance.validation.invalid_datetime',
        translateMessage(translate, 'governance.validation.invalid_datetime', { field: label }),
        { field_key: field.key },
      );
    }
  }

  for (const relation of relationships) {
    if (relation?.required === true && relationSelectionCount(relation, relationSelections) === 0) {
      const label = localizedLabel(relation, translate);
      return validationFailure(
        'governance.validation.relation_required',
        translateMessage(translate, 'governance.validation.relation_required', { relation: label }),
        { relationship_key: relation.key },
      );
    }
  }

  const validFrom = normalizeString(form?.valid_from);
  const validUntil = normalizeString(form?.valid_until);
  if (validFrom !== '' && validUntil !== '' && Date.parse(validUntil) <= Date.parse(validFrom)) {
    return validationFailure(
      'governance.validation.valid_until_after_from',
      translateMessage(translate, 'governance.validation.valid_until_after_from'),
      { field_key: 'valid_until' },
    );
  }

  return { ok: true };
}
