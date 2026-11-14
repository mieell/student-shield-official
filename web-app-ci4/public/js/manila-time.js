(() => {
    const MANILA_TIMEZONE = 'Asia/Manila';
    const DATE_ONLY_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
    const NAIVE_DATETIME_PATTERN = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/;

    function parsePHDate(value) {
        if (value instanceof Date) {
            return Number.isNaN(value.getTime()) ? new Date() : new Date(value.getTime());
        }
        if (typeof value === 'number') {
            return new Date(value);
        }
        if (!value) {
            return new Date();
        }
        const raw = String(value).trim();
        if (!raw || raw.startsWith('0000-00-00')) {
            return new Date();
        }
        if (DATE_ONLY_PATTERN.test(raw)) {
            return new Date(raw + 'T00:00:00+08:00');
        }
        if (NAIVE_DATETIME_PATTERN.test(raw)) {
            return new Date(raw.replace(' ', 'T') + '+08:00');
        }
        return new Date(raw);
    }

    function formatPHDate(value, options = {}, locale = 'en-PH') {
        const dateObj = value instanceof Date ? value : parsePHDate(value);
        if (Number.isNaN(dateObj.getTime())) {
            return '';
        }
        return new Intl.DateTimeFormat(locale, Object.assign({ timeZone: MANILA_TIMEZONE }, options)).format(dateObj);
    }

    function formatPHIsoDate(value) {
        const dateObj = value instanceof Date ? value : parsePHDate(value);
        if (Number.isNaN(dateObj.getTime())) {
            return '';
        }
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: MANILA_TIMEZONE,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).formatToParts(dateObj);
        const map = {};
        parts.forEach(part => { map[part.type] = part.value; });
        return `${map.year}-${map.month}-${map.day}`;
    }

    function formatPHTimeAgo(value, nowMs = Date.now()) {
        if (!value) {
            return '';
        }
        const diff = Math.max(0, Math.floor((nowMs - parsePHDate(value).getTime()) / 1000));
        if (diff < 60) return diff + 's ago';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    window.parsePHDate = parsePHDate;
    window.formatPHDate = formatPHDate;
    window.formatPHIsoDate = formatPHIsoDate;
    window.formatPHTimeAgo = formatPHTimeAgo;
})();
