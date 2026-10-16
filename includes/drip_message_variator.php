<?php

const DRIP_RANDOMIZATION_MODES = ['none','minimal','human','ghost','chaos'];

if (!function_exists('drip_seeded_rand')) {
function drip_seeded_rand(int $seed): int
{
    $state = hash('fnv1a64', (string)$seed, true);
    $val = (int)(unpack('V', substr($state, 4, 4))[1] & 0x7FFFFFFF);
    return max(1, $val % 2147483647 + 1);
}
}

function drip_select_variant(array $variants, int $contactId, int $stepId = 0, ?int $variantIndexOverride = null): string
{
    if (empty($variants)) return '';
    $idx = $variantIndexOverride !== null
        ? $variantIndexOverride
        : drip_seeded_rand($contactId * 997 + $stepId * 13) % count($variants);
    return $variants[$idx] ?? $variants[0];
}

/**
 * Generate N text variants from a base template with enhanced variation.
 * Supports custom variables: {{name}}, {{1}}, {{contact_name}}, {{company}}, {{product}}
 */
function drip_generate_variants(string $baseText, string $contactName, int $contactId, int $count = 3, int $stepId = 0): array
{
    $baseText = trim($baseText);
    if ($baseText === '') return [];

    $variants   = [];
    $seed       = $contactId * 997 + $stepId * 13 + 7;

    // Enhanced gender detection with broader indicators
    $isLikelyFemale = false;
    if ($contactName !== '') {
        $firstWord = strtolower(explode(' ', $contactName)[0]);
        $femaleIndicators = [
            'priya','kavya','ananya','aishwarya','devi','lakshmi','sneha','riya','neha',
            'sita','gita','meena','kumari','rani','puja','sunita','kiran','divya','sakshi',
            'pooja','isha','ahana','aanya','anika','aaradhya','vanya','isha','kiara','navya',
            'prisha','yashvi','shreya','bhavna','rachna','geeta','radha','seema','rekha',
            'bharti','kavita','jyoti','deepa','neelam','asha','usha','reema','nayana',
            'roshni','kajal','tara','laxmi','saroj','maya','kala','shanti','prema',
            'shobha','usha','leela','chhaya','bina','sushma','manju','padmini','shakti',
        ];
        foreach ($femaleIndicators as $ind) {
            if (str_contains($firstWord, $ind)) {
                $isLikelyFemale = true;
                break;
            }
        }
    }

    for ($i = 0; $i < $count; $i++) {
        $text   = $baseText;
        $vSeed  = $seed + $i * 311;

        $text = drip_vary_greeting($text, $vSeed, $contactName, $isLikelyFemale);

        if (drip_seeded_rand($vSeed) % 100 < 60) {
            $text = drip_apply_synonyms($text, $vSeed);
        }

        if (!preg_match('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}]/u', $text)) {
            $text = drip_apply_emojis($text, $vSeed);
        }

        if (drip_seeded_rand($vSeed + 1) % 100 < 30) {
            $text = drip_permute_openers($text, $vSeed);
        }

        // 30% chance to insert a pause/thinking indicator
        if (drip_seeded_rand($vSeed + 3) % 100 < 30 && $contactName !== '') {
            $pauseOptions = [
                '… ',
                '— ',
                '— just to let you know, ',
                '… quick thought: ',
                ', by the way, ',
            ];
            $pauseIdx = drip_seeded_rand($vSeed + 5) % count($pauseOptions);
            $text = substr_replace($text, $pauseOptions[$pauseIdx], strpos($text, ' ') ?: 0, 0);
        }

        $variants[] = $text;
    }

    $variants = array_values(array_unique($variants));

    if (count($variants) === 1 && $count > 1) {
        $alt = drip_apply_synonyms($baseText, $seed + 9999);
        $alt = drip_apply_emojis($alt, $seed + 9998, true);
        if ($alt !== $variants[0]) {
            $variants[] = $alt;
        }
    }

    $shuffleSeed = $seed + 42;
    mt_srand($shuffleSeed);
    shuffle($variants);
    mt_srand();

    return array_values($variants);
}

function drip_vary_greeting(string $text, int $seed, string $contactName, bool $isLikelyFemale = false): string
{
    $hour = (int) date('G');

    if ($contactName === '') return $text;

    $replacement = $contactName;

    // Extended greeting pools with cultural variety
    if ($hour >= 5 && $hour < 12) {
        $pool = ['Good morning', 'Morning', 'Hi', 'Hello', 'Hey there', 'Greetings', 'Rise and shine'];
        $greeting = $pool[drip_seeded_rand($seed) % count($pool)];
    } elseif ($hour >= 12 && $hour < 17) {
        $pool = ['Good afternoon', 'Afternoon', 'Hi', 'Hello', 'Hey', 'Hope your day is going well'];
        $greeting = $pool[drip_seeded_rand($seed + 1) % count($pool)];
    } elseif ($hour >= 17 && $hour < 21) {
        $pool = ['Good evening', 'Evening', 'Hi', 'Hello', 'Hey', 'Good to hear from you'];
        $greeting = $pool[drip_seeded_rand($seed + 2) % count($pool)];
    } else {
        $catchAll = ['Hi', 'Hello', 'Hey there', 'Hi there'];
        $greeting = $catchAll[drip_seeded_rand($seed + 3) % count($catchAll)];
    }

    $text = preg_replace(
        '/^(Hi|Hello|Hey|Hey there|Good\s+(morning|afternoon|evening|day))[\s,]*(' . preg_quote($contactName, '/') . '|there|friend|dear)/i',
        $greeting . ' ' . $replacement,
        $text,
        1
    );

    $text = str_replace(
        ['{{name}}', '{{contact_name}}', '{{1}}', '{{Name}}', '{{NAME}}'],
        $replacement,
        $text
    );

    if ($isLikelyFemale) {
        $genderedPool = ['ma\'am', $replacement, 'madam'];
        $gendered     = $genderedPool[drip_seeded_rand($seed + 7) % count($genderedPool)];
        $text         = preg_replace('/\bthere\b|\bfriend\b/i', $gendered, $text, 1);
    }

    // Handle leftover placeholders gracefully
    $text = preg_replace('/\{\{\w+\}\}/', '', $text);

    return $text;
}

function drip_apply_emojis(string $text, int $seed, bool $force = false): string
{
    if (!$force && preg_match('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}]/u', $text)) return $text;

    $emojiSets = [
        ['👋','🤝','🙌','👍','✨'],
        ['🚀','💫','🌟','⭐','💡'],
        ['🙏','🤗','💬','📢','🎯'],
        ['📅','🗓️','⏰','🔔','📆'],
        ['🏠','🏡','🏘️','📋','📌'],
        ['🎉','🎊','🥳','🎁','💝'],
        ['📞','💬','💭','🗨️','✉️'],
        ['✅','👌','🙆','💪','🤩'],
        ['💎','🔥','⚡','🎯','💯'],
        ['🤝','💼','📊','📈','🏆'],
    ];

    $set = $emojiSets[drip_seeded_rand($seed + 5) % count($emojiSets)];
    $em  = $set[drip_seeded_rand($seed + 6) % count($set)];

    $text = preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}\x{2000}-\x{206F}\x{FE0F}]+/u', '', $text);
    $text = trim($text);

    return $em . ' ' . $text;
}

function drip_apply_synonyms(string $text, int $seed): string
{
    $synonymMap = [
        'schedule'     => ['book','arrange','set up','confirm','reserve'],
        'book'         => ['schedule','reserve','confirm','arrange','lock in'],
        'confirm'      => ['finalize','secure','lock in','complete','wrap up'],
        'exclusive'    => ['special','limited','unique','premium','invite-only'],
        'offer'        => ['deal','opportunity','bonus','perk','gift','proposition'],
        'limited'      => ['rare','exclusive','few spots','small batch','short window'],
        'interested'   => ['keen','curious','open','aligned','keen to explore','intrigued'],
        'help'         => ['assist','guide','support','walk you through','show you','help out'],
        'contact'      => ['reach out','connect','talk','get in touch','message','ping'],
        'call'         => ['chat','talk','connect','reach out','conversation','ring'],
        'buy'          => ['get','pick up','grab','claim','secure','invest in'],
        'learn more'   => ['discover','find out','see details','read more','dig deeper','explore'],
        'message'      => ['text','note','update','info','word','line'],
        'reminder'     => ['ping','nudge','heads-up','check-in','alert','friendly reminder'],
        'follow up'    => ['check back','reconnect','circle back','touch base','loop back','come back to'],
        'free'         => ['no cost','on us','complimentary','our treat','gratis','zero cost'],
        'fast'         => ['quick','rapid','speedy','prompt','swift','brisk'],
        'easy'         => ['simple','straightforward','painless','effortless','quick','seamless'],
        'special'      => ['exclusive','curated','handpicked','tailored','unique','bespoke'],
        'today'        => ['right now','now','this morning','today itself','as soon as possible'],
        'reply'        => ['respond','answer','reach back','get back','send a note','write back'],
        'details'      => ['info','specifics','particulars','facts','full breakdown','lowdown'],
        'price'        => ['rate','cost','investment','pricing','fee','figure'],
        'discount'     => ['saving','deal','markdown','reduction','bonus value','price cut'],
        'urgent'       => ['time-sensitive','priority','important','pressing','critical'],
        'available'    => ['in stock','ready','on hand','open','accessible','reachable'],
        'quality'      => ['standard','grade','caliber','class','value'],
        'new'          => ['fresh','latest','brand new','just launched','recent','upcoming'],
        'guarantee'    => ['assurance','warranty','pledge','promise','commitment'],
        'service'      => ['support','assistance','care','attention','help'],
        'customer'     => ['client','patron','buyer','shopper','visitor'],
        'payment'      => ['transaction','remittance','settlement','deposit','installment'],
        'delivery'     => ['shipping','dispatch','fulfillment','handover','dispatch'],
        'satisfaction' => ['happiness','fulfillment','contentment','delight','approval'],
        'visit'        => ['come by','drop in','stop by','swing by','check out'],
    ];

    $rng = drip_seeded_rand($seed + 77);
    $keys = array_keys($synonymMap);
    $numToSwap = 2 + ($rng % 4);
    $chosen    = [];
    $tmpSeed   = $seed + 101;
    for ($i = 0; $i < $numToSwap; $i++) {
        $chosen[] = $keys[drip_seeded_rand($tmpSeed + $i) % count($keys)];
    }

    foreach ($chosen as $word) {
        $opt = $synonymMap[$word];
        $rep = $opt[drip_seeded_rand($seed + 202) % count($opt)];
        if ($rep !== $word) {
            $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', $rep, $text, 1);
        }
    }

    return $text;
}

function drip_permute_openers(string $text, int $seed): string
{
    $openers = [
        'Just a heads-up — ',
        'Quick update — ',
        'Thought you might like this — ',
        'Here\'s something for you — ',
        'Don\'t miss this — ',
        'A little something we wanted to share — ',
        'Following up on this — ',
        'Just reaching out — ',
        'Wanted to check in — ',
        'A quick note — ',
        'Just circling back — ',
        'Heads up — ',
    ];
    $closers = [
        'Thanks!','Appreciate it!','Looking forward to hearing from you.',
        'Let us know!','Hope to see you soon!','Talk soon!',
        'Cheers!','Looking forward!','Speak soon!','Warmly,',
    ];

    $selO = $openers[drip_seeded_rand($seed + 300) % count($openers)];
    $selC = $closers[drip_seeded_rand($seed + 301) % count($closers)];

    $alreadyOpens = false;
    foreach ($openers as $op) {
        if (stripos($text, trim($op, ' -')) === 0) { $alreadyOpens = true; break; }
    }
    if (!$alreadyOpens && !preg_match('/^(Hi|Hello|Hey|Good\s+)/i', $text)) {
        $text = $selO . ' ' . $text;
    }

    $textEnd = strtolower(trim(substr($text, -35)));
    $knownClosers = ['thanks','thank you','appreciate it','looking forward','talk soon','cheers','warmly'];
    $hasCloser = false;
    foreach ($knownClosers as $kc) {
        if (str_contains($textEnd, $kc)) { $hasCloser = true; break; }
    }
    if (!$hasCloser) {
        $text .= ' ' . $selC;
    }

    return $text;
}

function drip_next_variant_index(?array $visitedVariants, array $variants, int $contactId, int $stepId = 0): int
{
    if (empty($variants)) return 0;
    if (empty($visitedVariants)) return drip_seeded_rand($contactId * 997 + $stepId * 13) % count($variants);

    $total = count($variants);
    if (count($visitedVariants) >= $total) return drip_seeded_rand($contactId * 997 + $stepId * 13 + 99) % $total;

    $start = drip_seeded_rand($contactId * 997 + $stepId * 13 + 77) % $total;
    for ($i = 0; $i < $total; $i++) {
        $idx = ($start + $i) % $total;
        if (!in_array($idx, $visitedVariants, true)) {
            return $idx;
        }
    }
    return $start;
}
