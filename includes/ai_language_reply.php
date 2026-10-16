<?php
/**
 * Infer customer message language for consistent AI replies.
 * Supports Tamil, Telugu, Malayalam, Kannada, Hindi, English, Tanglish, Hinglish, Manglish.
 */

/**
 * Whisper verbose_json may return ISO codes ("ta") or full names ("tamil", "malayalam").
 */
function waamini_normalize_whisper_language(?string $raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $r = strtolower(trim($raw));
    static $map = [
        'tamil'     => 'ta', 'telugu' => 'te', 'malayalam' => 'ml', 'hindi'   => 'hi',
        'english'   => 'en', 'kannada' => 'kn', 'marathi'  => 'mr', 'gujarati' => 'gu',
        'bengali'   => 'bn', 'punjabi' => 'pa', 'odia'     => 'or', 'urdu'    => 'ur',
    ];
    if (isset($map[$r])) {
        return $map[$r];
    }
    if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $r)) {
        return substr($r, 0, 2);
    }
    return strlen($r) >= 2 ? substr($r, 0, 2) : null;
}

/** ISO 639-1 → English name for prompts */
function waamini_language_display_name(string $iso): string
{
    static $map = [
        'ta'      => 'Tamil',
        'te'      => 'Telugu',
        'ml'      => 'Malayalam',
        'kn'      => 'Kannada',
        'hi'      => 'Hindi',
        'en'      => 'English',
        'tanglish' => 'Tanglish (Tamil + English Roman script)',
        'hinglish' => 'Hinglish (Hindi + English Roman script)',
        'manglish' => 'Manglish (Malayalam + English Roman script)',
    ];
    return $map[strtolower($iso)] ?? strtoupper($iso);
}

/**
 * Detect Tanglish, Hinglish, or Manglish from Latin-script text.
 * Uses common word-lists to distinguish regional romanised dialects.
 *
 * Returns 'tanglish', 'hinglish', 'manglish', or null (pure English).
 */
function waamini_detect_romanised_dialect(string $text): ?string
{
    $lower = strtolower($text);

    // Tamil-origin words written in English (Tanglish)
    $tanglishWords = [
        // Common verb forms and expressions
        'venum','vendum','vendaam','vendam','vaanga','sollu','solunga','sollunga',
        'epdi','eppadi','enna','enakku','enaku','naa','na','naan','nan','avan','aval',
        'enga','unga','inga','ange','inge','ulla','veliya','romba','konjam','paaru','pakku',
        'vachuruken','vachuriken','irukku','iruku','iruken','illai','illa','aama','aana',
        'sari','theriyum','theriyala','purigudha','puriala','pesuvom','pesalama',
        'kandippa','mudiyadhu','mudiyum','ponga','poitu','vandhu','vanthu','kitayadhu',
        'kitaikum','maela','keela','paakalam','pakalama','pannuvom','pannunga',
        'machan','da','di','macha','kelu','kelunga','maathi','podu',
        // Postpositions and connectors (very common in Tanglish WhatsApp messages)
        'ku','la','oda','ila','laam','um','thaan','dhaan','pathi','pathu',
        'kita','kitta','mela','keezhla','pakka','pakkam','munnadi','pinnadi',
        // Additional verb forms
        'pannu','pannalam','panalam','pannuven','pannunga','pannuren',
        'vaanga','vaayen','vaanuken','kudukuven','edukkuven',
        'solren','solluren','ketpen','kettuken','paapen','paappom',
        'pogalam','poglam','varalama','varalam','iruntha','irundha',
        // Common nouns/adverbs
        'enoda','enadu','ungaloda','avaloda','avanoda','ippo','appo','appuram',
        'naalaikku','inaikku','nethu','mundhi','thevai','vishayam',
        'vilaiyaattu','kadhai','kaasu','panam','velai','dhandha',
        'kattanam','thittam','mudhal','kadaisi','sema','mass',
        // Business context Tanglish
        'evlo','evalavu','yenna','yepdi','yeppadi',
        // WhatsApp business message verbs
        'anupanum','anuppunga','anuppanum','anuppuren','anuppalam',
        'vaanganum','vaangunga','vaangalam','connect','panren','pannuren',
        'kudunga','kudukkanum','edukanum','edukkanum',
        'paakanum','paakkanum','sollunga','therinjikanum',
    ];

    // Hindi-origin words (Hinglish)
    $hinglishWords = [
        'kya','kyun','kaise','kaisa','kaisi','nahi','nai','haan','haa','hai','ho',
        'bhai','yaar','bol','bolo','kar','karo','dena','lena','acha','accha','thik',
        'matlab','mera','meri','tera','teri','uska','uski','unka','aap','tum','main',
        'abhi','aur','bas','phir','toh','lekin','magar','sach','jhooth','bilkul',
        'zaroor','jarur','seedha','sidha','samajh','samjha','chahiye','chahie',
    ];

    // Malayalam-origin words (Manglish)
    $manglishWords = [
        'alle','alleda','undo','undo','und','illa','illatha','engane','engane',
        'ethra','enthu','entinu','adipoli','machi','chetta','chechi','athe',
        'sheriyano','ayyo','njan','njangal','ningal','avide','ivide','ivideyund',
        'parayam','parayan','tharunno','tharam','venam','venda',
    ];

    $tScore = 0;
    $hScore = 0;
    $mScore = 0;

    // Use word-boundary matching to prevent partial matches (e.g. "much" triggering "mu" hits)
    $words = preg_split('/\W+/', $lower, -1, PREG_SPLIT_NO_EMPTY);
    $wordSet = array_flip($words); // O(1) lookup

    foreach ($tanglishWords as $w) {
        if (isset($wordSet[$w])) {
            $tScore++;
        }
    }
    foreach ($hinglishWords as $w) {
        if (isset($wordSet[$w])) {
            $hScore++;
        }
    }
    foreach ($manglishWords as $w) {
        if (isset($wordSet[$w])) {
            $mScore++;
        }
    }

    // Require at least 1 Tanglish word OR at least 2 Hinglish/Manglish words to avoid false positives
    if ($tScore === 0 && $hScore < 2 && $mScore < 2) {
        return null; // pure English
    }

    $max = max($tScore, $hScore, $mScore);
    if ($max === 0) {
        return null; // pure English
    }

    if ($tScore >= $hScore && $tScore >= $mScore) return 'tanglish';
    if ($hScore >= $mScore) return 'hinglish';
    return 'manglish';
}

/**
 * Count Indic/Latin letters to pick dominant script (Whisper's language tag can confuse Tamil/Telugu).
 *
 * @param string|null $whisperIso Whisper verbose_json language (optional)
 */
function waamini_resolve_reply_language_iso(string $text, ?string $whisperIso): ?string
{
    $text = trim($text);
    $whisperIso = waamini_normalize_whisper_language($whisperIso);

    if ($text === '' || preg_match('/^\[(audio|image|video|document|interactive|voice)\s+received\]$/i', $text)) {
        return $whisperIso ?: null;
    }

    $tamil  = @preg_match_all('/[\x{0B80}-\x{0BFF}]/u', $text) ?: 0;
    $telugu = @preg_match_all('/[\x{0C00}-\x{0C7F}]/u', $text) ?: 0;
    $mly    = @preg_match_all('/[\x{0D00}-\x{0D7F}]/u', $text) ?: 0;
    $knda   = @preg_match_all('/[\x{0C80}-\x{0CFF}]/u', $text) ?: 0;
    $deva   = @preg_match_all('/[\x{0900}-\x{097F}]/u', $text) ?: 0;
    $latin  = @preg_match_all('/[A-Za-z]/', $text) ?: 0;

    $scores = ['ta' => $tamil, 'te' => $telugu, 'ml' => $mly, 'kn' => $knda, 'hi' => $deva];
    arsort($scores);
    $topIso = array_key_first($scores);
    $top    = $scores[$topIso] ?? 0;

    if ($top === 0) {
        if ($latin > 0) {
            // Latin-script text: try to detect Tanglish / Hinglish / Manglish
            $dialect = waamini_detect_romanised_dialect($text);
            if ($dialect !== null) {
                return $dialect; // 'tanglish', 'hinglish', 'manglish'
            }
            return 'en';
        }
        return $whisperIso ?: null;
    }

    // Tie between Tamil & Telugu: prefer Whisper if it picks one of them
    if ($tamil === $telugu && $tamil > 0 && $whisperIso) {
        if ($whisperIso === 'ta' || $whisperIso === 'te') {
            return $whisperIso;
        }
    }

    return $topIso;
}

/**
 * Hard constraint appended to system prompt.
 * Enforces exact language + tone mirroring of the customer.
 */
function waamini_strict_language_instruction(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '';
    }

    $iso  = strtolower(trim($iso));
    $name = waamini_language_display_name($iso);

    // Tanglish — extremely forced script rule
    if ($iso === 'tanglish') {
        return <<<TXT

--- !!! CRITICAL LANGUAGE RULE (HIGHEST PRIORITY — OVERRIDES ALL OTHER RULES) !!! ---
The customer is writing in **TANGLISH** (Tamil words written in English/Roman letters, mixed with English words).

YOU MUST REPLY IN TANGLISH. This means:
1. USE ONLY ENGLISH LETTERS (A-Z) — never use Tamil script (தமிழ்).
2. MIX Tamil words + English words naturally, exactly like how the customer writes.
3. DO NOT reply in pure formal English. This is the MOST IMPORTANT rule.
4. Use casual Tamil words like: venum, epdi, pannalam, sollunga, paakalam, iruku, etc.
5. Keep English words for technical/brand terms: API, WhatsApp, business, CRM, demo, setup, etc.
6. Sound like a friendly Tamil person chatting on WhatsApp in Tanglish.

WRONG (DO NOT DO THIS): "Waamini helps construction businesses with automated follow-ups and lead nurturing."
RIGHT (DO THIS): "Bro, construction business ku Waamini use panna automated follow-up, lead nurturing ella ready ah iruku. Epdi start pannanum nu sollunga! 🚀"

MORE EXAMPLES:
Customer: "Enoda construction business ku epdi api use panalam"
Reply: "Construction business ku API setup romba easy bro! Waamini la WhatsApp API connect panna 20 mins thaan. Oru demo paakalam? 🔥"

Customer: "Bulk message anupanum"
Reply: "Bulk campaign la oru click la 1000+ customers ku message anupalam. Epdi maari messages anuppanum? 💪"

Customer: "Price evlo"
Reply: "Price ungaloda business size pathi depend agum. Oru quick 20-min demo la best plan solluven — free ah paakalam! 📞"

TXT;
    }

    // Hinglish
    if ($iso === 'hinglish') {
        return <<<TXT

--- STRICT LANGUAGE OUTPUT RULES (NON-NEGOTIABLE) ---
The customer is writing in **Hinglish** (Hindi words in Roman/English letters, mixed with English).

YOU MUST:
1. Reply in Hinglish — same casual Hindi+English Roman mix the customer used.
2. Mirror their tone: casual (yaar/bhai) → be casual; polite → be polite.
3. DO NOT reply in formal English. DO NOT reply in Devanagari script.
4. Keep it short — 2-3 sentences, WhatsApp style.
5. Sound like a friendly sales person, not a chatbot.

TXT;
    }

    // Manglish
    if ($iso === 'manglish') {
        return <<<TXT

--- STRICT LANGUAGE OUTPUT RULES (NON-NEGOTIABLE) ---
The customer is writing in **Manglish** (Malayalam words in Roman/English letters, mixed with English).

YOU MUST:
1. Reply in Manglish — same casual Malayalam+English Roman mix the customer used.
2. Mirror their tone exactly.
3. DO NOT reply in formal English. DO NOT reply in Malayalam script.
4. Keep it short — 2-3 sentences, WhatsApp style.

TXT;
    }

    // Tamil script — dedicated, detailed instruction with examples
    if ($iso === 'ta') {
        return <<<TXT

--- STRICT LANGUAGE OUTPUT RULES (NON-NEGOTIABLE) ---
The customer wrote in **Tamil** (தமிழ்) script.

YOU MUST:
1. Write your ENTIRE reply in natural, spoken Tamil (பேச்சு தமிழ்) — NOT formal/literary Tamil (இலக்கிய தமிழ்).
2. Use everyday conversational Tamil like how people actually chat on WhatsApp in Tamil Nadu.
3. Proper nouns and brand names (WhatsApp, Waamini, Bulk Campaign, CRM, API, Demo) may stay in English letters.
4. Technical/business terms that people commonly say in English can stay in English (e.g., "message", "campaign", "setup", "demo call"). Do NOT force-translate every English word into Tamil — that sounds robotic.
5. Keep it short — 2-3 sentences max, WhatsApp style.
6. Sound like a friendly person chatting, not a textbook or Google Translate output.

COMMON MISTAKES TO AVOID:
- Do NOT use "சாப்பிடும்போது" (eating) when you mean "use பண்ணும்போது" (while using).
- Do NOT use overly literary words like "விபரங்களா" — use "details" or "தகவல்" instead.
- Do NOT create broken phrases by literally translating English grammar into Tamil.
- Do NOT mix up "மாதம்" (month) with "நிமிடம்" (minute) or other time words.

GOOD EXAMPLES:
Customer: "Bulk campaign பத்தி சொல்லுங்க"
Reply: "Bulk campaign மூலமா ஒரே நேரத்துல 1000+ customers-க்கு message அனுப்பலாம். உங்க business-க்கு எந்த மாதிரி campaign தேவை? 🚀"

Customer: "WhatsApp API use பண்ணா number block ஆகுமா?"
Reply: "Official API use பண்ணா block ஆகாது, அது Meta approved. Waamini-ல blocking issues இல்லாம safely campaigns run பண்ணலாம். ஒரு demo பாக்கலாமா? 👍"

Customer: "Setup எவ்வளவு நேரம் ஆகும்?"
Reply: "Setup 20-30 நிமிடம் தான், ரொம்ப easy. உங்களுக்கு எப்போ convenient-ஆ இருக்கும், ஒரு demo call போடலாமா? 📞"

TXT;
    }

    // Other native Indian scripts (Telugu, Malayalam, Kannada, Hindi, etc.)
    if (in_array($iso, ['te', 'ml', 'kn', 'hi', 'mr', 'gu', 'bn', 'pa'], true)) {
        return <<<TXT

--- STRICT LANGUAGE OUTPUT RULES (NON-NEGOTIABLE) ---
The customer wrote in **{$name}** script.

YOU MUST:
1. Write your ENTIRE reply in natural, conversational {$name} — NOT formal/literary style.
2. Use everyday spoken {$name} like how people actually chat on WhatsApp.
3. Proper nouns/brand names (WhatsApp, Waamini, Meta, API, CRM, Demo) may stay in English letters.
4. Technical/business terms commonly used in English can stay in English. Do NOT force-translate every word.
5. Keep it short — 2-3 sentences, WhatsApp style.
6. Sound like a friendly person chatting, not a textbook or translation engine.

TXT;
    }

    // Pure English (en)
    return <<<TXT

--- STRICT LANGUAGE OUTPUT RULES (NON-NEGOTIABLE) ---
The customer wrote in English. Reply in clear, conversational English.
Keep it short (2-3 sentences), friendly, and direct — not formal or corporate.

TXT;
}
