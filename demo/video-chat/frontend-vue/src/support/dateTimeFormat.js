export const SUPPORTED_DATE_FORMATS = Object.freeze([
  'dmy_dot',
  'dmy_slash',
  'dmy_dash',
  'ymd_dash',
  'ymd_slash',
  'ymd_dot',
  'ymd_compact',
  'mdy_slash',
  'mdy_dash',
  'mdy_dot',
]);

export const DATE_FORMAT_OPTIONS = Object.freeze([
  { value: 'dmy_dot', label: 'DD.MM.YYYY' },
  { value: 'dmy_slash', label: 'DD/MM/YYYY' },
  { value: 'dmy_dash', label: 'DD-MM-YYYY' },
  { value: 'ymd_dash', label: 'YYYY-MM-DD (ISO)' },
  { value: 'ymd_slash', label: 'YYYY/MM/DD' },
  { value: 'ymd_dot', label: 'YYYY.MM.DD' },
  { value: 'ymd_compact', label: 'YYYYMMDD' },
  { value: 'mdy_slash', label: 'MM/DD/YYYY' },
  { value: 'mdy_dash', label: 'MM-DD-YYYY' },
  { value: 'mdy_dot', label: 'MM.DD.YYYY' },
]);

function pad2(value) {
  return String(value).padStart(2, '0');
}

function toDate(value) {
  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value;
  }

  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }

  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

export function normalizeTimeFormat(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return normalized === '12h' ? '12h' : '24h';
}

export function normalizeDateFormat(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return SUPPORTED_DATE_FORMATS.includes(normalized) ? normalized : 'dmy_dot';
}

function formatDateParts(date, format) {
  const year = String(date.getFullYear());
  const month = pad2(date.getMonth() + 1);
  const day = pad2(date.getDate());

  switch (format) {
    case 'dmy_slash':
      return `${day}/${month}/${year}`;
    case 'dmy_dash':
      return `${day}-${month}-${year}`;
    case 'ymd_dash':
      return `${year}-${month}-${day}`;
    case 'ymd_slash':
      return `${year}/${month}/${day}`;
    case 'ymd_dot':
      return `${year}.${month}.${day}`;
    case 'ymd_compact':
      return `${year}${month}${day}`;
    case 'mdy_slash':
      return `${month}/${day}/${year}`;
    case 'mdy_dash':
      return `${month}-${day}-${year}`;
    case 'mdy_dot':
      return `${month}.${day}.${year}`;
    case 'dmy_dot':
    default:
      return `${day}.${month}.${year}`;
  }
}

function formatTimeParts(date, format) {
  const minutes = pad2(date.getMinutes());
  const normalizedTimeFormat = normalizeTimeFormat(format);
  if (normalizedTimeFormat === '12h') {
    const hours = date.getHours();
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const hours12 = hours % 12 || 12;
    return `${pad2(hours12)}:${minutes} ${suffix}`;
  }

  return `${pad2(date.getHours())}:${minutes}`;
}

export function formatDateDisplay(value, options = {}) {
  const fallback = typeof options.fallback === 'string' && options.fallback !== '' ? options.fallback : 'n/a';
  const date = toDate(value);
  if (!date) {
    return typeof value === 'string' && value.trim() !== '' ? value : fallback;
  }

  return formatDateParts(date, normalizeDateFormat(options.dateFormat));
}

export function formatDateTimeDisplay(value, options = {}) {
  const fallback = typeof options.fallback === 'string' && options.fallback !== '' ? options.fallback : 'n/a';
  const date = toDate(value);
  if (!date) {
    return typeof value === 'string' && value.trim() !== '' ? value : fallback;
  }

  const dateDisplay = formatDateParts(date, normalizeDateFormat(options.dateFormat));
  const timeDisplay = formatTimeParts(date, options.timeFormat);
  return `${dateDisplay} ${timeDisplay}`;
}

export function formatDateRangeDisplay(startsAt, endsAt, options = {}) {
  const separator = typeof options.separator === 'string' && options.separator !== '' ? options.separator : ' -> ';
  const sharedOptions = {
    dateFormat: options.dateFormat,
    timeFormat: options.timeFormat,
    fallback: typeof options.fallback === 'string' ? options.fallback : 'n/a',
  };

  return `${formatDateTimeDisplay(startsAt, sharedOptions)}${separator}${formatDateTimeDisplay(endsAt, sharedOptions)}`;
}

export function formatWeekdayShort(value, options = {}) {
  const fallback = typeof options.fallback === 'string' ? options.fallback : '';
  const date = toDate(value);
  if (!date) {
    return fallback;
  }

  try {
    return new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(date);
  } catch {
    return fallback;
  }
}

export function fullCalendarEventTimeFormat(timeFormat) {
  return {
    hour: '2-digit',
    minute: '2-digit',
    hour12: normalizeTimeFormat(timeFormat) === '12h',
  };
}
