/**
 * static/diet_import.js
 * ─────────────────────────────────────────────────────────────────
 * Shared "Import from ChatGPT" engine used by Add New Phase and
 * Edit Diet Plan (create_diet_template.php keeps its own copy of
 * this logic; keep the two in sync if you change parsing rules
 * here).
 *
 * Exposes window.DietImport = { parseDietText, extractTextFromPdf, fillDietForm }
 */
(function () {

    // Parse a free-text diet plan (ChatGPT output, any layout) into named sections + metadata.
    function parseDietText(raw) {
        const clean = s => s
            .replace(/\*\*/g, '')
            .replace(/`/g, '')
            .replace(/^[^\p{L}\p{N}]+/u, '')
            .replace(/^\d+[.)]\s+/, '')
            .replace(/\s*\|\s*/g, ' ')
            .trim();

        // strip clock times / numeric ranges: "7:00 AM", "6:00–7:15 PM", "11 AM", "60–90 min", "1–2"
        const stripTimes = s => s
            .replace(/\b\d{1,2}:\d{2}\s*(?:[–—-]\s*\d{1,2}:\d{2})?\s*(?:[ap]\.?\s*m\.?)?/gi, ' ')
            .replace(/\b\d{1,2}\s*(?:[ap]\.?\s*m\.?)\b/gi, ' ')
            .replace(/\b\d{1,3}\s*[–—-]\s*\d{1,3}\b/g, ' ');

        // first clock time in a line -> hour as float (24h); null if none
        const clockHour = (line) => {
            const m = line.match(/\b(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s*m\b/i);
            if (!m) return null;
            let h = parseInt(m[1], 10) % 12;
            if (/p/i.test(m[3])) h += 12;
            return h + (m[2] ? parseInt(m[2], 10) / 60 : 0);
        };
        // the visible time / time-range text in a line ("7:00 AM", "1130 AM", "6:30–7:00 PM") or null
        const grabTime = (line) => {
            const m = line.match(/\b(?:\d{3,4}|\d{1,2}(?:[:.]\d{2})?)\s*(?:[–—-]\s*\d{1,2}(?:[:.]\d{2})?)?\s*[ap]\.?\s*m\.?/i);
            return m ? m[0].replace(/\s*\.\s*/g, '').replace(/\s+/g, ' ').trim().toUpperCase() : null;
        };
        const slotFromHour = (h) => {
            if (h == null) return null;
            if (h >= 4 && h < 7.5) return 'wake_up';
            if (h >= 7.5 && h < 10.5) return 'breakfast';
            if (h >= 10.5 && h < 12.5) return 'snack';
            if (h >= 12.5 && h < 15.5) return 'lunch';
            if (h >= 15.5 && h < 18.75) return 'snack';
            if (h >= 18.75 && h < 22) return 'dinner';
            return 'pre_sleep';
        };

        const SKIP = /^(choose(?: any)?(?: one| 1)?|pick one|for example|for eg|e\.?g\.?|use this(?: simple)? plate structure|plate structure|option\s*\w+|non-?vegetarian option|non-?veg option|vegetarian option|veg option|about|approx|approximately|or|and|plus|time|meal|food|item|items|when|what)$/i;
        const AVOID_HEAD = /^(avoid|avoid these|avoid the following|things to avoid|foods to avoid|don'?t eat|do not eat|strictly avoid)$/i;
        const ADVICE = /^(try to |prefer |avoid |keep (?:dinner|lunch|breakfast|it|the)|don'?t |do not |no |note[:\s]|tip[:\s]|aim to |aim for |make sure|drink at least|stay hydrated|limit |reduce |for (?:someone|people|those|a person)|about \d|you (?:don'?t|should|can|may|need|must))/i;
        const MACRO = /^(protein|carb|carbs|carbohydrate|carbohydrates|fat|fats|calorie|calories|kcal|fiber|fibre|macros?|total)\s*[:~=]/i;

        const SECTIONS = [
            { field: '__ignore', keys: ['workout', 'work out', 'training', 'training session', 'exercise', 'exercises', 'gym', 'gym session', 'resistance training', 'weight training', 'cardio', 'strength training'] },
            { field: 'wake_up', keys: ['wake up', 'wake-up', 'wakeup', 'on waking', 'on rising', 'early morning', 'empty stomach', 'first thing', 'morning ritual', 'morning drink', 'after waking', 'am ritual'] },
            { field: 'post_workout', keys: ['post workout', 'post-workout', 'after workout', 'intra workout', 'intra-workout', 'pre workout', 'pre-workout', 'workout meal', 'during workout', 'post training', 'pre training'] },
            { field: 'breakfast', keys: ['breakfast', 'morning meal', 'first meal', 'meal 1', 'meal one', 'b/fast', 'brk', 'am meal'] },
            { field: 'lunch', keys: ['lunch', 'afternoon meal', 'midday meal', 'mid day meal', 'noon', 'afternoon', 'meal 3', 'meal three'] },
            { field: 'snack', keys: ['mid meal', 'mid-meal', 'midmeal', 'mid morning', 'mid-morning', 'midmorning', 'mid day snack', 'mid-day snack', 'evening snack', 'evening', 'snack', 'snacks', 'brunch', 'tea time', 'tea-time', 'teatime', 'meal 2', 'meal two', 'meal 4', 'meal four', 'between meals'] },
            { field: 'dinner', keys: ['dinner', 'supper', 'evening meal', 'night meal', 'last meal', 'meal 5', 'meal five', 'meal 6', 'pm meal'] },
            { field: 'pre_sleep', keys: ['pre sleep', 'pre-sleep', 'presleep', 'bed time', 'bedtime', 'before bed', 'before sleep', 'night cap', 'nightcap', 'post dinner', 'post-dinner', 'optional', 'optional meal', 'bedtime snack', 'night snack', 'late night', 'night', 'before sleeping'] },
            { field: 'guidelines', keys: ['guideline', 'guidelines', 'note', 'notes', 'instruction', 'instructions', 'tips', 'general tips', 'important', 'rules', 'do and don', 'dos and don', 'general guidelines', 'if hungry', 'if still hungry', 'if you feel hungry', 'general advice', 'other tips', 'hydration', 'water intake'] }
        ];

        const norm = (line) => stripTimes(clean(line).toLowerCase())
            .replace(/\([^)]*\)/g, ' ')
            .replace(/[—–\-:•·|]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        // A few section keys are also ordinary English words that turn up mid-sentence
        // in body text ("Optional: 5-6 almonds" under an earlier heading; "...at night").
        // Real meal names (breakfast/lunch/dinner/snack/...) never do this, so only these
        // need the extra "the whole line must be short" guard below.
        const AMBIGUOUS_WORDS = new Set(['optional', 'night']);

        const matchSection = (str) => {
            if (str.length > 42) return null;
            for (const s of SECTIONS) {
                for (const k of s.keys) {
                    const kk = k.replace(/-/g, ' ');
                    if (str === kk) return s.field;
                    const ambiguous = !kk.includes(' ') && AMBIGUOUS_WORDS.has(kk);
                    const shortEnough = str.length <= kk.length + 6;
                    if (str.startsWith(kk + ' ') && (!ambiguous || shortEnough)) return s.field;
                    if (str.startsWith(kk) && shortEnough) return s.field;
                }
            }
            return null;
        };

        const headerField = (line) => {
            let l = norm(line);
            let f = matchSection(l);
            if (!f) {
                // Some PDFs render a heading's leading emoji/icon (🌅, 🍳, a bullet glyph…)
                // using a font with no real Unicode mapping, so text extraction spits out a
                // stray 1-2 char token instead (e.g. "n Breakfast" instead of "🍳 Breakfast").
                // Retry once with that kind of leading noise token stripped.
                const stripped = l.replace(/^[a-z0-9]{1,2}\s+(?=[a-z])/, '');
                if (stripped !== l) f = matchSection(stripped);
            }
            if (f) return f;
            // name-less time header: "7:00 AM", "🍳 8:00 AM", "Meal 1 (8 AM)", "8:00–9:00 AM"
            const leftover = l.replace(/^(meal|session|slot)\s*\d*/i, '').trim();
            if (leftover.length <= 3 && clockHour(line) != null) return slotFromHour(clockHour(line));
            return null;
        };

        const buckets = {};
        let current = null;
        let avoidMode = false;

        const pushLine = (bucket, txt) => {
            const c = clean(txt);
            if (!c) return;
            const bare = c.replace(/:\s*$/, '');
            if (MACRO.test(c)) return;
            if (AVOID_HEAD.test(bare)) { avoidMode = true; return; }
            if (SKIP.test(bare)) { avoidMode = false; return; }
            if (bucket === '__ignore') { if (ADVICE.test(c)) (buckets.guidelines = buckets.guidelines || []).push(c); return; }
            if (avoidMode) { (buckets.guidelines = buckets.guidelines || []).push('Avoid: ' + c); return; }
            if (ADVICE.test(c)) { (buckets.guidelines = buckets.guidelines || []).push(c); return; }
            (buckets[bucket] = buckets[bucket] || []).push(c);
        };

        raw.split(/\r?\n/).forEach(rawLine => {
            const line = rawLine.replace(/\t/g, ' ');
            if (!line.trim()) return;

            // ---- markdown / pipe table row ----
            if ((line.match(/\|/g) || []).length >= 2) {
                const cells = line.split('|').map(c => c.trim()).filter(Boolean);
                if (!cells.length || cells.every(c => /^[-:\s]+$/.test(c))) return;
                let field = null;
                for (const cell of cells) { const ff = headerField(cell); if (ff && ff !== '__ignore') { field = ff; break; } }
                if (!field) for (const cell of cells) { const s = slotFromHour(clockHour(cell)); if (s) { field = s; break; } }
                if (field) {
                    const tCell = cells.map(c => grabTime(c)).find(Boolean);
                    if (tCell) (buckets[field] = buckets[field] || []).push(tCell);
                    const food = cells
                        .filter(c => headerField(c) === null && clockHour(c) == null && !/^(time|meal|food|slot|when)$/i.test(c))
                        .sort((a, b) => b.length - a.length)[0];
                    if (food) food.split(/[;,]\s*|\s*\/\s*(?=[A-Z])/).forEach(part => pushLine(field, part));
                    current = field;
                }
                return;
            }

            const f = headerField(line);
            if (f) {
                current = f;
                avoidMode = false;
                if (!buckets[f]) buckets[f] = [];
                // keep the time that was on the section/header line
                if (f !== '__ignore') { const t = grabTime(line); if (t) buckets[f].push(t); }
                const noParen = stripTimes(line.replace(/\([^)]*\)/g, ''));
                const ci = noParen.indexOf(':');
                if (ci !== -1) {
                    const after = clean(noParen.slice(ci + 1));
                    if (after.length >= 3 && !/^[\d\s–—-]*(?:am|pm)?$/i.test(after) && !SKIP.test(after.replace(/:\s*$/, ''))) {
                        after.split(/,\s*(?=[A-Za-z0-9])/).forEach(part => pushLine(f, part));
                    }
                }
                return;
            }
            if (current) pushLine(current, line);
        });

        const out = { fields: {}, meta: {} };
        Object.keys(buckets).forEach(f => { if (f !== '__ignore' && buckets[f].length) out.fields[f] = buckets[f].join('\n'); });

        const low = raw.toLowerCase();
        if (/\bvegan\b/.test(low)) out.meta.diet_type = 'vegan';
        else if (/non[\s-]?veg|nonveg|eggetarian|chicken|fish|mutton|prawn|\begg\b|\beggs\b/.test(low)) out.meta.diet_type = 'nonveg';
        else if (/\bveg\b|vegetarian/.test(low)) out.meta.diet_type = 'veg';

        const calM = low.match(/(\d{3,5})\s*(?:k?cal|calories|kcals)/);
        if (calM) out.meta.calories = calM[1];

        const wkM = low.match(/(\d{1,2})\s*(?:week|wk)s?\b/);
        if (wkM) out.meta.duration = wkM[1];
        else { const mM = low.match(/(\d{1,2})\s*months?\b/); if (mM) out.meta.duration = String(parseInt(mM[1], 10) * 4); }

        const gm = raw.match(/goal\s*[:\-]\s*(.+)/i);
        if (gm) out.meta.goal = clean(gm[1]).split(/[.\n]/)[0].trim();
        else if (/fat loss|weight loss|cutting|lose weight|reduce weight|slim/i.test(raw)) out.meta.goal = 'Fat Loss';
        else if (/muscle (gain|build)|bulking|lean mass|hypertrophy|mass gain|muscle building/i.test(raw)) out.meta.goal = 'Muscle Building';
        else if (/maintenance|maintain weight|general health|wellness|diabet|blood sugar|healthy eating/i.test(raw)) out.meta.goal = 'General Health';

        return out;
    }

    // Extracts text from an uploaded PDF (any layout) using pdf.js, reconstructing
    // line breaks from each glyph's on-page position.
    async function extractTextFromPdf(file) {
        if (!window.pdfjsLib) {
            throw new Error('PDF reader failed to load (check your internet connection) — please paste the text instead.');
        }
        const buf = await file.arrayBuffer();
        const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
        const pages = [];

        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            const page = await pdf.getPage(pageNum);
            const content = await page.getTextContent();

            // Group text items into lines using their vertical position (transform[5]),
            // since a PDF has no inherent concept of "lines" — only positioned glyphs.
            const lines = [];
            const Y_TOLERANCE = 3;
            content.items.forEach(item => {
                const x = item.transform[4];
                const y = item.transform[5];
                let line = lines.find(l => Math.abs(l.y - y) <= Y_TOLERANCE);
                if (!line) {
                    line = { y, parts: [] };
                    lines.push(line);
                }
                line.parts.push({ x, str: item.str });
            });

            // Pages render top-to-bottom but PDF y-coordinates increase upward.
            lines.sort((a, b) => b.y - a.y);

            const pageText = lines
                .map(l => l.parts.sort((a, b) => a.x - b.x).map(p => p.str).join(' ').replace(/\s+/g, ' ').trim())
                .filter(t => t !== '')
                .join('\n');
            pages.push(pageText);
        }

        return pages.join('\n\n');
    }

    // Fills a <form>'s meal/meta fields from a parseDietText() result. Works whether
    // diet_type/goal are plain <input>s or <select>s (the three pages that use this
    // aren't consistent with each other), flashing each touched field green.
    function fillDietForm(form, parsed) {
        if (!form || !parsed) return 0;
        let filled = 0;

        const flash = el => {
            el.style.transition = 'background .4s';
            el.style.background = '#ecfdf5';
            setTimeout(() => { el.style.background = ''; }, 1000);
        };

        const setPlain = (name, val) => {
            if (val === undefined || val === null || val === '') return;
            const el = form.querySelector('[name="' + name + '"]');
            if (!el) return;
            el.value = val;
            filled++;
            flash(el);
        };

        // For fields that could be a free-text <input> OR a <select> of preset options
        // (diet_type, goal): match by value/text (case-insensitive, prefix for goal).
        const setFuzzy = (name, val) => {
            if (!val) return;
            const el = form.querySelector('[name="' + name + '"]');
            if (!el) return;
            if (el.tagName === 'SELECT') {
                const want = String(val).toLowerCase();
                const first = want.split(/[\s/]/)[0];
                const opt = [...el.options].find(o =>
                    o.value.toLowerCase() === want ||
                    o.text.toLowerCase() === want ||
                    o.text.toLowerCase().startsWith(first));
                if (opt) { el.value = opt.value; filled++; flash(el); }
            } else {
                el.value = val;
                filled++;
                flash(el);
            }
        };

        ['wake_up', 'post_workout', 'breakfast', 'lunch', 'snack', 'dinner', 'pre_sleep', 'guidelines']
            .forEach(f => setPlain(f, parsed.fields[f]));
        setPlain('calories', parsed.meta.calories);
        setPlain('duration', parsed.meta.duration);
        setFuzzy('diet_type', parsed.meta.diet_type);
        setFuzzy('goal', parsed.meta.goal);

        return filled;
    }

    window.DietImport = { parseDietText, extractTextFromPdf, fillDietForm };
})();
