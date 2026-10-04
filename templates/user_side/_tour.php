<?php
/**
 * templates/user_side/_tour.php
 * ─────────────────────────────────────────────────────────────────
 * First-login walkthrough of the member portal.
 *
 * It runs across all five portal pages: each step names the page it belongs to
 * and the element it points at (marked up with data-tour="..."), and the last
 * step of a page moves the member on to the next one. Progress is saved after
 * every move (auth/tour_helper.php), so the tour picks up where it left off
 * rather than restarting on each page load.
 *
 * Included by _shell_bottom.php and user_dashboard.php, both of which already
 * have $conn and $uid. Once the tour is finished it stays hidden and inert, but
 * is still rendered so "Take a tour" in the sidebar can start it again.
 */

require_once __DIR__ . '/../../auth/tour_helper.php';

$tour_step = user_tour_step($conn, $uid);
$tour_page = basename($_SERVER['PHP_SELF'], '.php');   // e.g. "user_dashboard"
$tour_csrf = generate_csrf_token();
?>
<style>
    /* Invisible sheet that swallows clicks on the page while the tour runs */
    .tour-block {
        position: fixed;
        inset: 0;
        z-index: 9000;
    }

    /* The dim and the highlight are ONE element: the hole is the element itself
       and the dimming is its (very large) box-shadow. Four separate panels used
       to slide past each other between steps and darken where they overlapped. */
    .tour-spot {
        position: fixed;
        z-index: 9001;
        border-radius: 14px;
        box-shadow: 0 0 0 4px rgba(255, 107, 71, .35), 0 0 0 9999px rgba(17, 20, 28, .62);
        outline: 2.5px solid var(--coral, #FF6B47);
        outline-offset: -1px;
        pointer-events: none;
        opacity: 0;
        transition: opacity .25s ease, top .32s cubic-bezier(.4, 0, .2, 1), left .32s cubic-bezier(.4, 0, .2, 1), width .32s cubic-bezier(.4, 0, .2, 1), height .32s cubic-bezier(.4, 0, .2, 1);
    }

    .tour-on .tour-spot {
        opacity: 1;
    }

    /* Welcome / finish: no element to point at, so the hole closes to nothing
       and the same shadow dims the whole screen */
    .tour-spot.blank {
        outline-color: transparent;
        box-shadow: 0 0 0 9999px rgba(17, 20, 28, .62);
        transition: opacity .25s ease;
    }

    .tour-pop {
        position: fixed;
        z-index: 9002;
        width: min(330px, calc(100vw - 32px));
        background: #fff;
        border-radius: 16px;
        padding: 18px 20px 16px;
        box-shadow: 0 18px 50px -12px rgba(17, 20, 28, .45);
        font-family: 'Inter', system-ui, sans-serif;
        opacity: 0;
        transform: translateY(6px);
        transition: opacity .22s ease, transform .22s ease, top .3s cubic-bezier(.4, 0, .2, 1), left .3s cubic-bezier(.4, 0, .2, 1);
    }

    .tour-on .tour-pop {
        opacity: 1;
        transform: translateY(0);
    }

    .tour-pop .count {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: var(--coral, #FF6B47);
        margin-bottom: 6px;
    }

    .tour-pop h4 {
        font-family: 'Sora', 'Inter', sans-serif;
        font-size: 16px;
        font-weight: 700;
        color: #1E2230;
        margin: 0 0 6px;
    }

    .tour-pop p {
        font-size: 13.5px;
        line-height: 1.5;
        color: #5B6472;
        margin: 0 0 14px;
    }

    .tour-acts {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
    }

    .tour-skip {
        border: none;
        background: none;
        color: #9CA3AF;
        font-family: inherit;
        font-size: 12.5px;
        font-weight: 600;
        cursor: pointer;
        padding: 6px 2px;
        margin-right: auto;
    }

    .tour-skip:hover {
        color: #6B7280;
    }

    .tour-btn {
        border: none;
        border-radius: 10px;
        padding: 9px 16px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
    }

    .tour-back {
        background: #F1F3F6;
        color: #4B5563;
    }

    .tour-back:hover {
        background: #E6E9EE;
    }

    .tour-next {
        background: var(--coral, #FF6B47);
        color: #fff;
    }

    .tour-next:hover {
        background: var(--coral-dark, #E5502B);
    }

    /* Welcome / finish card: nothing to point at, so it sits in the middle.
       Roomier than the step cards — this one is read, not glanced at. */
    .tour-pop.tour-center {
        width: min(468px, calc(100vw - 32px));
        top: 50%;
        left: 50%;
        transform: translate(-50%, -48%);
        text-align: center;
        padding: 40px 38px 30px;
        border-radius: 20px;
        box-shadow: 0 24px 64px -22px rgba(17, 20, 28, .45);
    }

    .tour-on .tour-pop.tour-center {
        transform: translate(-50%, -50%);
    }

    .tour-center .tour-emoji {
        font-size: 40px;
        line-height: 1;
        margin-bottom: 20px;
    }

    .tour-pop.tour-center h4 {
        font-size: 22px;
        letter-spacing: -.015em;
        margin-bottom: 12px;
        text-wrap: balance;
    }

    .tour-pop.tour-center p {
        font-size: 14.5px;
        line-height: 1.65;
        margin: 0 auto 10px;
        max-width: 352px;
        text-wrap: pretty;
    }

    .tour-center .tour-meta {
        display: block;
        font-size: 12.5px;
        font-weight: 600;
        color: #A8AEB8;
    }

    .tour-pop.tour-center .tour-acts {
        justify-content: center;
        gap: 14px;
        margin-top: 26px;
    }

    .tour-pop.tour-center .tour-skip {
        margin-right: 0;
        padding: 11px 4px;
    }

    .tour-center .tour-next {
        padding: 12px 28px;
        font-size: 14px;
    }

    @media (prefers-reduced-motion: reduce) {

        .tour-spot,
        .tour-pop {
            transition: none;
        }
    }
</style>

<div id="tourRoot" aria-live="polite">
    <div class="tour-block"></div>
    <div class="tour-spot"></div>
    <div class="tour-pop" role="dialog" aria-modal="true" aria-labelledby="tourTitle"></div>
</div>

<script>
    (function () {
        const ROOT = document.getElementById('tourRoot');
        const PAGE = <?= json_encode($tour_page) ?>;
        const CSRF = <?= json_encode($tour_csrf) ?>;
        const START_AT = <?= (int) $tour_step ?>;   // 0 = not started, N = next step, -1 = done

        // The walkthrough in order. `page` is the file it runs on and `el` is the
        // data-tour attribute of the element it points at; a step with no `el` is a
        // centred card. Steps whose element is missing (no plan yet, no payments
        // yet) are skipped automatically, so the tour fits whatever the member has.
        const STEPS = [
            {
                page: 'user_dashboard', center: true, emoji: '👋',
                title: 'Welcome to your member portal',
                text: 'A short walkthrough of where everything lives: your membership, diet plan, payments and messages.',
                meta: '10 steps · about a minute'
            },
            {
                page: 'user_dashboard', el: 'nav', place: 'right', mobileEl: 'menu-btn',
                title: 'Your menu',
                text: 'Your profile, membership, diet plan and payments all live here.'
            },
            {
                page: 'user_dashboard', el: 'messages', place: 'bottom',
                title: 'Messages',
                text: 'Replies from your trainer show up here, with a dot when something is new.'
            },
            {
                page: 'user_dashboard', el: 'welcome-stats', place: 'bottom',
                title: 'Your progress at a glance',
                text: 'Your workout streak, how many workouts you have logged, and how many of your daily goals you have achieved.'
            },
            {
                page: 'user_dashboard', el: 'membership-card', place: 'right',
                title: 'Your membership',
                text: 'Your current plan, when it expires and how many days are left.'
            },
            {
                page: 'user_dashboard', el: 'payment-card', place: 'left',
                title: 'Payment status',
                text: 'Anything still owing on your plan, your last payment and the next due date.'
            },
            {
                page: 'user_dashboard', el: 'streak-card', place: 'top',
                title: 'Workout streak',
                text: 'Tap a day to log your workout and keep your streak going. Tap a logged day again to add a note.',
                meta: 'Tip: log it the same day so the streak keeps counting.'
            },
            {
                page: 'user_dashboard', el: 'goals-card', place: 'left',
                title: 'Daily goals',
                text: 'Set your goals for the day with the + button, then tick each one off as you achieve it.'
            },
            {
                page: 'user_dashboard', el: 'goals-card', place: 'left',
                title: 'Look back at any day',
                text: 'The three dates at the top switch days, and the arrows step further back so you can see how earlier days went.',
                nextPage: 'user_profile.php'
            },
            {
                page: 'user_profile', el: 'profile-form', place: 'top',
                title: 'Keep your profile current',
                text: 'Your details, health info and measurements. Your trainer builds your plan from these, so keep them up to date and save.',
                meta: 'The red dot in the menu means something is still missing.'
            },
            {
                page: 'user_profile', el: 'profile-photos', place: 'top',
                title: 'Progress photos',
                text: 'Upload front, side and back photos to track your physique. Your first photo sits beside every later one so you can see the change.',
                meta: 'You can keep up to 5 photo phases — delete one to add more.',
                nextPage: 'user_membership.php'
            },
            {
                page: 'user_membership', el: 'membership-plans', place: 'top',
                title: 'Plans and renewals',
                text: 'Your current plan sits at the top. Browse the plans below to subscribe or renew.',
                meta: 'Renewal opens in the last 10 days of your plan.',
                nextPage: 'user_diet_plans.php'
            },
            {
                page: 'user_diet_plans', el: 'diet-head', place: 'bottom',
                title: 'Your diet plan',
                text: 'Everything your trainer has set for you: meals, calories and the goal for this phase.'
            },
            {
                page: 'user_diet_plans', el: 'week-switch', place: 'bottom',
                title: 'Switch between weeks',
                text: 'Every phase your trainer has written for you is in this list. Pick any one to read it, or download it as a PDF.'
            },
            {
                page: 'user_diet_plans', el: 'chat-tabs', place: 'top',
                title: 'Two ways to ask',
                text: 'Ask Trainer sends a question to your trainer. FitJo is an AI assistant that answers about your own plan straight away.'
            },
            {
                page: 'user_diet_plans', el: 'diet-chat', place: 'top',
                title: 'Ask about this plan',
                text: 'Questions about your plan go here. Replies from your trainer arrive in your messages.',
                nextPage: 'user_payments.php'
            },
            {
                page: 'user_payments', el: 'payments', place: 'bottom',
                title: 'Payments and invoices',
                text: 'What you have paid, anything still due, and invoices to download.'
            },
            {
                page: 'user_payments', el: 'pay-dues', place: 'top',
                title: 'Pay what is due',
                text: 'Pay a pending balance here: scan the QR, then send the amount, the transaction ID and a screenshot. Your trainer verifies it and your balance updates.'
            },
            {
                page: 'user_payments', center: true, emoji: '🎉',
                title: "That's everything",
                text: 'You are all set. You can run this tour again any time from Take a tour in the menu.',
                last: true
            }
        ];

        const pop = ROOT.querySelector('.tour-pop');
        const spot = ROOT.querySelector('.tour-spot');

        let idx = -1;   // index into STEPS of the step on screen
        let target = null;

        const isMobile = () => window.matchMedia('(max-width: 860px)').matches;
        const find = step => {
            const key = (isMobile() && step.mobileEl) ? step.mobileEl : step.el;
            return key ? document.querySelector('[data-tour="' + key + '"]') : null;
        };

        function save(step) {
            const body = new FormData();
            body.append('_csrf_token', CSRF);
            body.append('step', step);
            // sendBeacon survives the page navigating away mid-tour, which a plain
            // fetch may not. Either way a failed save never blocks the tour.
            if (navigator.sendBeacon && navigator.sendBeacon('../../handlers/user_tour.php', body)) return;
            fetch('../../handlers/user_tour.php', { method: 'POST', body: body, keepalive: true }).catch(() => { });
        }

        function close(done) {
            ROOT.classList.remove('tour-on');
            ROOT.style.display = 'none';
            if (done) save(-1);
        }

        function place(step) {
            // Centred card (welcome / finish): dim everything, point at nothing
            if (!target) {
                spot.classList.add('blank');
                spot.style.cssText = 'top:50%;left:50%;width:0;height:0;';
                pop.classList.add('tour-center');
                pop.style.top = '';
                pop.style.left = '';
                return;
            }

            pop.classList.remove('tour-center');
            spot.classList.remove('blank');

            const r = target.getBoundingClientRect();
            const pad = 6;
            const top = Math.max(0, r.top - pad), left = Math.max(0, r.left - pad);
            const w = r.width + pad * 2, h = r.height + pad * 2;
            const vw = window.innerWidth, vh = window.innerHeight;

            spot.style.cssText = 'top:' + top + 'px;left:' + left + 'px;width:' + w + 'px;height:' + h + 'px;';

            // Put the card on whichever side has room, preferring the step's choice
            const pr = pop.getBoundingClientRect();
            const gap = 14;
            const room = {
                bottom: vh - (top + h) - gap, top: top - gap,
                right: vw - (left + w) - gap, left: left - gap
            };
            let side = step.place || 'bottom';
            if (side === 'right' && room.right < pr.width) side = room.left >= pr.width ? 'left' : 'bottom';
            if (side === 'left' && room.left < pr.width) side = room.right >= pr.width ? 'right' : 'bottom';
            if (side === 'bottom' && room.bottom < pr.height) side = room.top >= pr.height ? 'top' : 'bottom';
            if (side === 'top' && room.top < pr.height) side = room.bottom >= pr.height ? 'bottom' : 'top';
            if (isMobile() && (side === 'left' || side === 'right')) {
                side = room.bottom >= pr.height ? 'bottom' : 'top';
            }

            let pTop, pLeft;
            if (side === 'bottom' || side === 'top') {
                pTop = side === 'bottom' ? top + h + gap : top - pr.height - gap;
                pLeft = left + w / 2 - pr.width / 2;
            } else {
                pLeft = side === 'right' ? left + w + gap : left - pr.width - gap;
                pTop = top + h / 2 - pr.height / 2;
            }
            pop.style.top = Math.min(Math.max(12, pTop), vh - pr.height - 12) + 'px';
            pop.style.left = Math.min(Math.max(12, pLeft), vw - pr.width - 12) + 'px';
        }

        function render(step) {
            const n = STEPS.filter(s => !s.center).length;
            const shown = STEPS.slice(0, idx + 1).filter(s => !s.center).length;
            const first = idx === 0;

            const head = step.center
                ? '<div class="tour-emoji">' + step.emoji + '</div>'
                : '<div class="count">Step ' + shown + ' of ' + n + '</div>';

            pop.innerHTML = head +
                '<h4 id="tourTitle"></h4><p></p>' +
                (step.meta ? '<span class="tour-meta"></span>' : '') +
                '<div class="tour-acts">' +
                (step.last ? '' : '<button type="button" class="tour-skip">Skip tour</button>') +
                (first || step.last ? '' : '<button type="button" class="tour-btn tour-back">Back</button>') +
                '<button type="button" class="tour-btn tour-next">' +
                (step.last ? 'Go to dashboard' : first ? 'Start tour' : 'Next') + '</button></div>';
            if (step.meta) pop.querySelector('.tour-meta').textContent = step.meta;
            pop.querySelector('h4').textContent = step.title;
            pop.querySelector('p').textContent = step.text;

            const skip = pop.querySelector('.tour-skip');
            if (skip) skip.addEventListener('click', () => close(true));
            const back = pop.querySelector('.tour-back');
            if (back) back.addEventListener('click', () => go(-1));
            pop.querySelector('.tour-next').addEventListener('click', () => {
                if (!step.last) { go(1); return; }
                // Finishing drops them back on the dashboard, where they started
                close(true);
                window.location.href = 'user_dashboard.php';
            });
        }

        // Moves by `dir` steps, hopping over any step whose element isn't on the
        // page and following the step's nextPage when it leads somewhere else.
        function go(dir) {
            let i = idx;
            for (;;) {
                const from = STEPS[i];
                i += dir;
                if (i < 0) return;
                if (i >= STEPS.length) { close(true); return; }

                const step = STEPS[i];
                if (step.page !== PAGE) {
                    // Going forward we follow the page the last step pointed to;
                    // going back we return to that page's saved step.
                    save(i + 1);
                    const href = dir > 0 ? (from.nextPage || step.page + '.php') : step.page + '.php';
                    window.location.href = href;
                    return;
                }
                target = find(step);
                if (step.center || target) { show(i, step); return; }
            }
        }

        function show(i, step) {
            idx = i;
            save(i + 1);
            render(step);
            place(step);
            if (!target) return;

            target.scrollIntoView({ block: 'center', behavior: 'smooth' });
            // Smooth scrolling has no reliable "finished" event, so follow the
            // element until it stops moving, then place the card for real.
            let last = null, still = 0, frames = 0;
            (function settle() {
                if (idx !== i) return;
                const y = target.getBoundingClientRect().top;
                still = (last !== null && Math.abs(y - last) < 0.5) ? still + 1 : 0;
                last = y;
                place(step);
                if (still < 3 && ++frames < 90) requestAnimationFrame(settle);
            })();
        }

        function start(fromStep) {
            ROOT.style.display = '';
            ROOT.classList.add('tour-on');
            idx = fromStep - 2;   // saved steps are 1-based; go(1) lands on fromStep
            go(1);
        }

        // The blocker swallows clicks so the page can't be used mid-tour
        ROOT.querySelector('.tour-block').addEventListener('click', e => e.stopPropagation());
        document.addEventListener('keydown', e => {
            if (!ROOT.classList.contains('tour-on')) return;
            if (e.key === 'Escape') close(true);
            if (e.key === 'ArrowRight') go(1);
            if (e.key === 'ArrowLeft') go(-1);
        });
        let resizeTimer = null;
        window.addEventListener('resize', () => {
            if (!ROOT.classList.contains('tour-on') || idx < 0) return;
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => { target = find(STEPS[idx]); place(STEPS[idx]); }, 120);
        });
        window.addEventListener('scroll', () => {
            if (ROOT.classList.contains('tour-on') && idx >= 0) place(STEPS[idx]);
        }, { passive: true, capture: true });

        // "Take a tour" in the sidebar restarts it from the dashboard
        document.querySelectorAll('[data-tour-restart]').forEach(btn => {
            btn.addEventListener('click', e => {
                e.preventDefault();
                save(1);
                if (PAGE === 'user_dashboard') { start(1); } else { window.location.href = 'user_dashboard.php'; }
            });
        });

        ROOT.style.display = 'none';
        if (START_AT === 0 && PAGE === 'user_dashboard') {
            start(1);                       // first login: open by itself
        } else if (START_AT > 0) {
            const next = STEPS[START_AT - 1];
            if (next && next.page === PAGE) start(START_AT);   // carry on where they left off
        }
    })();
</script>
