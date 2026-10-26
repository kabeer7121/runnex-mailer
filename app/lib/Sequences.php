<?php
declare(strict_types=1);

/**
 * Built-in, ready-to-use cold email sequences for truck dispatch services.
 * Written for deliverability: short, personal, spintax on every line, no links or images
 * in the first email, follow-ups reply in the same thread (empty subject).
 */
final class Sequences
{
    public static function all(): array
    {
        return [
            'classic' => [
                'name' => 'Dispatch Services – Classic Pitch',
                'tag' => 'Main cold email',
                'about' => 'The full pitch for owner-operators and small fleets. Explains what you do, handles common objections and ends politely. 5 emails over about 2 weeks.',
                'steps' => [
                    [0, '{Loads|Freight|Keeping your truck loaded} for {{company_name|your trucks}}',
                        "<p>{Hi|Hello|Hey} {{first_name|there}},</p>"
                        . "<p>{I came across|I found} {{company_name|your company}} and wanted to reach out. {We're|I'm with} {{my_company|a truck dispatch service}} – we {work with|dispatch for} owner-operators and small fleets running {{equipment_type|dry van, reefer and flatbed}}.</p>"
                        . "<p>{What we handle|What we take off your plate}:</p>"
                        . "<ul><li>{Finding|Booking} {good-paying|higher-paying} loads {that fit your lanes|on the lanes you want}</li><li>Negotiating rates with brokers</li><li>Carrier packets, rate cons and broker paperwork</li><li>Invoicing {and staying on top of|and following up on} payments</li></ul>"
                        . "<p>{You stay in control|You always have the final say} – {no forced dispatch|we never force a load on you}, and you choose your home time.</p>"
                        . "<p>{Would it make sense to|Are you open to} a {quick|short} {5-minute|10-minute} call {this week|in the next few days}?</p>"
                        . '<p>{{signature}}</p>'],
                    [2, '', "<p>{Hi|Hey} {{first_name|there}}, {just|quickly} {bumping this up|following up} in case it got buried.</p>"
                        . "<p>{Most carriers we talk to|A lot of carriers we work with} {are tired of|are frustrated with} low-paying broker loads and {hours on the phone|spending evenings on load boards}. That's {exactly|the part} we take over{,|} so {you can just drive|you can focus on driving}.</p>"
                        . "<p>{Is {{company_name|your company}} looking for more consistent freight right now?|Are you currently looking for steadier freight?}</p>"
                        . '<p>{{sender_first_name}}</p>'],
                    [3, '', "<p>{{first_name|Hi}}, {a quick note on|one thing I should mention about} how we work:</p>"
                        . "<ul><li>{You pick|You approve} every load before it's booked</li><li>{We only book|We aim for} loads {at or above|that meet} your minimum rate per mile</li><li>{No long contracts|No long-term contract} – {stop anytime|you can stop whenever you want}</li></ul>"
                        . "<p>{Would you like|Want} me to send over {our rates|our fee} and {how onboarding works|what we need to get started}?</p>"
                        . '<p>{{sender_first_name}}</p>'],
                    [4, '', "<p>{Hi|Hey} {{first_name|there}},</p>"
                        . "<p>{If it helps|To make it easy}, we can {start with one load|start with a single load} so you can see {how we work|the difference} before {deciding anything|committing}.</p>"
                        . "<p>{What does your truck usually run|Which lanes do you usually run} – {{state|your area}} outbound?</p>"
                        . '<p>{{sender_first_name}}</p>'],
                    [5, '', "<p>{{first_name|Hi}}, {I haven't heard back|I haven't heard from you}, so I'll {assume|guess} {the timing isn't right|now isn't a good time}.</p>"
                        . "<p>If you {ever need|need} {help keeping your truck loaded|a dispatcher} {down the road|later on}, just reply to this email{ and I'll take it from there|}.</p>"
                        . "<p>{Safe travels|Drive safe},<br>{{sender_first_name}}</p>"],
                ],
            ],
            'new_authority' => [
                'name' => 'New Authority Welcome',
                'tag' => 'Alternative approach',
                'about' => 'For carriers whose MC is newly active. Congratulates them and helps with the hard first months (broker setup, credit, first loads). 4 emails.',
                'steps' => [
                    [0, '{Congrats on|Nice work on} the new authority',
                        "<p>{Hi|Hello} {{first_name|there}},</p>"
                        . "<p>{Congrats on|Nice work on} getting {{company_name|your company}} {up and running|on the road}! The first few months {are the hardest|can be tough} – {lots of brokers|many brokers} {won't work with|hesitate to book} new authorities, and {setup paperwork|carrier packets} {take forever|eat up hours}.</p>"
                        . "<p>{We help|We specialize in helping} new carriers {get set up with brokers|get approved with brokers}, find {their first loads|steady first loads} and {build a good history|build a track record} fast.</p>"
                        . "<p>{Do you already have|Have you lined up} {consistent freight|steady loads} for your {{equipment_type|truck}}?</p>"
                        . '<p>{{signature}}</p>'],
                    [3, '', "<p>{Hi|Hey} {{first_name|there}}, {following up|just checking back} on my note.</p>"
                        . "<p>{A common problem|Something we see a lot} with new authorities: {brokers ask for|you need} 3–6 months of history{,|} {and|but} you can't get history without loads. We {work with|have relationships with} brokers who {do book|book} new MCs{, which helps break that cycle|}.</p>"
                        . "<p>{Worth a quick call?|Want to talk for 5 minutes this week?}</p>"
                        . '<p>{{sender_first_name}}</p>'],
                    [4, '', "<p>{{first_name|Hi}}, {one more thing|also} – we {take care of|handle} carrier packets, W-9s, {insurance certificates|COI requests} and rate confirmations, so {you're not stuck doing paperwork at midnight|you're not doing paperwork after a long day}.</p>"
                        . "<p>{Should I send you|Want me to send} {a quick overview|our rates}?</p><p>{{sender_first_name}}</p>"],
                    [5, '', "<p>{Hi|Hey} {{first_name|there}}, I'll {stop reaching out|leave it here} for now. {Wishing you a strong start|Good luck with the new authority}!</p>"
                        . "<p>If you {need help|want a dispatcher} {later|down the road}, just reply here.</p><p>{{sender_first_name}}</p>"],
                ],
            ],
            'pain_point' => [
                'name' => 'Rate per Mile – Pain Point',
                'tag' => 'Alternative approach',
                'about' => 'Leads with the carrier\'s problem (low rates, deadhead, time on load boards) before the offer. Works well for experienced owner-operators. 4 emails.',
                'steps' => [
                    [0, '{Rate per mile|Your rates} {on|for} {{equipment_type|your loads}}',
                        "<p>{Hi|Hey} {{first_name|there}},</p>"
                        . "<p>{Quick question|Honest question}: {how much time|how many hours} {do you spend|does {{company_name|your team}} spend} on load boards and {calling brokers|negotiating} {each week|every week}?</p>"
                        . "<p>{Most|A lot of} owner-operators we talk to {lose|give up} {hours every day|their evenings} {to it|to that}{ – and still end up with low-paying loads and deadhead miles|, and still get stuck with low rates and deadhead}.</p>"
                        . "<p>{That's what we fix|That's the part we take over}: {we find and negotiate the loads|we search, negotiate and book}, you {just drive|drive}.</p>"
                        . "<p>{Is that a problem for you right now|Does that sound familiar}?</p>"
                        . '<p>{{signature}}</p>'],
                    [3, '', "<p>{Hi|Hey} {{first_name|there}}, {to add to my last email|one more thought}:</p>"
                        . "<p>{Brokers|Many brokers} {lowball|push low rates on} carriers who {book on their own|don't negotiate hard}. {Our dispatchers|We} {negotiate every load|push back on every rate con} and {plan reloads|line up the next load} {before you deliver|ahead of time} {to cut deadhead|so you're not running empty}.</p>"
                        . "<p>{Would a 5-minute call make sense?|Open to a quick chat?}</p><p>{{sender_first_name}}</p>"],
                    [4, '', "<p>{{first_name|Hi}}, {here's how it works in practice|a quick picture of a normal week with us}:</p>"
                        . "<ul><li>You tell us your lanes, minimum rate and home time</li><li>We {book|line up} loads {that match|within those rules}</li><li>{We handle|We take care of} {broker calls, paperwork and invoicing|the calls, rate cons and invoices}</li></ul>"
                        . "<p>{Want me to send our fee and next steps?|Should I send the details?}</p><p>{{sender_first_name}}</p>"],
                    [5, '', "<p>{Hi|Hey} {{first_name|there}}, {I'll close the loop here|last note from me}. If {booking loads|finding good freight} {ever becomes a headache|gets in the way}, just reply {and we'll pick it up|and I'll help}.</p><p>{Safe travels|Drive safe},<br>{{sender_first_name}}</p>"],
                ],
            ],
            'lane_offer' => [
                'name' => 'Lane / Freight Offer',
                'tag' => 'Alternative approach',
                'about' => 'Freight-first approach: talks about the lanes and equipment you regularly book in their state. Short and specific – gets high reply rates. 4 emails.',
                'steps' => [
                    [0, '{{equipment_type|Freight}} {loads|freight} out of {{state|your area}}',
                        "<p>{Hi|Hello} {{first_name|there}},</p>"
                        . "<p>We {regularly book|book a lot of} {{equipment_type|freight}} {loads|freight} {out of|in and out of} {{state|your area}} and {are looking for|need} {a few more|more} {reliable|dependable} carriers{ like {{company_name|you}}|}.</p>"
                        . "<p>{Are you running|Do you run} {that area|those lanes} {right now|these days}, {and do you have capacity|and is your truck available} {next week|in the coming days}?</p>"
                        . '<p>{{signature}}</p>'],
                    [2, '', "<p>{Hi|Hey} {{first_name|there}}, {just following up|checking back} – {do you have|is there} {a truck|capacity} {available|open} {this week or next|soon}?</p>"
                        . "<p>{If you tell me|Just reply with} your {preferred lanes|usual lanes} and {minimum rate|target rate per mile}, I'll {see what we have|check what fits}.</p><p>{{sender_first_name}}</p>"],
                    [3, '', "<p>{{first_name|Hi}}, {to be upfront|so you know how it works}: we're a dispatch service – {we book and manage|we find, negotiate and book} {the loads|freight} for you, {handle broker paperwork|do the rate cons and paperwork} and {invoicing|send invoices}.</p>"
                        . "<p>{You keep full control of|You decide} which loads you take. {Want the details?|Should I send our fee?}</p><p>{{sender_first_name}}</p>"],
                    [5, '', "<p>{Hi|Hey} {{first_name|there}}, {I'll stop here|last one from me}. {If your truck opens up in {{state|your area}}|Whenever you need loads}, just reply and {I'll check what we have|we'll get you moving}.</p><p>{{sender_first_name}}</p>"],
                ],
            ],
            'soft_question' => [
                'name' => 'Soft Question – Ultra Short',
                'tag' => 'Alternative approach',
                'about' => 'Very short, curiosity-based emails that feel like a personal note. Best inbox placement of all sequences – great for new or recovering accounts. 4 emails.',
                'steps' => [
                    [0, '{Quick question|Question} {about|for} {{company_name|your trucks}}',
                        "<p>{Hi|Hey} {{first_name|there}},</p>"
                        . "<p>{Who handles|Who's in charge of} {booking loads|dispatch} {for|at} {{company_name|your company}} – {you or a dispatcher|you, or do you have a dispatcher}?</p>"
                        . "<p>{Thanks|Appreciate it},<br>{{sender_first_name}}</p>"],
                    [3, '', "<p>{{first_name|Hi}}, {did you see my question|any thoughts on my last note}? {We help|I help} owner-operators {keep their trucks loaded|find better-paying loads} {without spending hours on load boards|without the load board grind}.</p><p>{{sender_first_name}}</p>"],
                    [4, '', "<p>{Hi|Hey} {{first_name|there}}, {would it be worth|is it worth} {a 5-minute call|a quick call} to see if {we can help|we're a fit}? {If not, no worries at all|No pressure either way}.</p><p>{{sender_first_name}}</p>"],
                    [5, '', "<p>{{first_name|Hi}}, {I'll leave you be|I won't keep emailing}. {If you ever want|Whenever you need} help {with dispatch|finding loads}, {just reply anytime|reply here anytime}.</p><p>{{sender_first_name}}</p>"],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Steps in the shape the campaign editor uses. */
    public static function steps(string $key): array
    {
        $seq = self::get($key) ?? self::get('classic');
        return array_map(fn($s) => ['delay_days' => $s[0], 'subject' => $s[1], 'body_html' => $s[2]], $seq['steps']);
    }

    public static function totalDays(array $seq): int
    {
        return array_sum(array_map(fn($s) => (int)$s[0], $seq['steps']));
    }
}
