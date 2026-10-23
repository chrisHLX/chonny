<?php

// Data for the field manual (readings/steering-by-feedback): each module's self-check, the
// systems traps, Meadows' leverage points and the worksheet's example. The self-checks are
// recall checks shown in the page, not the lessons' mastery questions (those stay server-side).
return [
    'quiz' => [
        1 => [
            ['q' => 'What is the core pattern cybernetics studies?', 'o' => ['Machines that imitate human thought', 'A system sensing the gap between its state and a goal, and acting to close it', 'The flow of information through computer networks', 'Growth that compounds over time'], 'a' => 1, 'why' => 'Wiener defined it as control and communication in animals and machines. The common thread is goal-seeking through feedback: sense, compare, act.'],
            ['q' => 'Why did Watt\'s governor matter to the later theory?', 'o' => ['It was the first electrical feedback device', 'It showed a machine regulating itself, and Maxwell\'s analysis of why such devices oscillate began the mathematics of feedback', 'It proved feedback always produces stability', 'It was designed by Wiener'], 'a' => 1, 'why' => 'The governor was mechanical, and governors were known to "hunt". Maxwell\'s 1868 paper explained when they would and wouldn\'t settle.'],
        ],
        2 => [
            ['q' => 'A sales team is measured on a monthly report that arrives three weeks after month end. Which part of the loop is weakest?', 'o' => ['The actuator', 'The set point', 'The sensor: slow information delays every correction', 'The process'], 'a' => 2, 'why' => 'The loop can only react to what the sensor reports, when it reports it. A slow sensor adds delay to every correction (module 4 shows what that does).'],
            ['q' => 'What turns an open-loop system into a closed-loop one?', 'o' => ['A faster actuator', 'Measuring the result and feeding it back into the decision', 'A higher set point', 'Removing disturbances'], 'a' => 1, 'why' => 'Closed loop means the output is sensed and compared with the goal. Without that the system acts blind, however good its other parts are.'],
            ['q' => 'Turning the heating on because tonight\'s forecast is cold is an example of:', 'o' => ['Feedback', 'Feedforward', 'A reinforcing loop', 'Double-loop learning'], 'a' => 1, 'why' => 'Feedforward acts on a predicted disturbance before it affects the output. It helps, but forecasts are sometimes wrong, so you still need feedback.'],
        ],
        3 => [
            ['q' => 'A loop has four links with signs +, −, +, −. What kind of loop is it?', 'o' => ['Balancing', 'Reinforcing', 'It depends on the delay', 'Neither'], 'a' => 1, 'why' => 'Two minus signs is an even number, so the loop is reinforcing. An odd number of minus signs would make it balancing.'],
            ['q' => 'A product\'s sign-ups grew fast for months and have now flattened. Spending more on referral rewards barely helps. What does limits to growth suggest?', 'o' => ['Spend even more on referrals', 'Find and work on the balancing loop that is now limiting growth', 'Wait for growth to resume', 'Stop measuring sign-ups'], 'a' => 1, 'why' => 'Once a limit takes over, pushing the reinforcing loop has little effect. The leverage is in the constraint: market size, capacity, churn, price.'],
        ],
        4 => [
            ['q' => 'Why does a proportional-only controller leave a steady gap from the target when the system constantly loses heat?', 'o' => ['The sensor is broken', 'It acts only in proportion to the error, so some error must remain to keep the heater on', 'The gain is always too high', 'Delay makes the gap permanent'], 'a' => 1, 'why' => 'To hold the heater at the level that matches the heat loss, gain times error must equal that level, so the error can\'t be zero. Integral action fixes this.'],
            ['q' => 'A team reviews stock monthly and orders hard whenever it looks low. Stock swings between shortage and surplus. The most likely cause?', 'o' => ['Too little gain', 'A long delay combined with strong correction', 'A reinforcing loop', 'Unpredictable customers'], 'a' => 1, 'why' => 'Delay plus high gain produces oscillation: the team reacts to old information and overcorrects. Shorter cycles or gentler corrections both help.'],
        ],
        5 => [
            ['q' => 'What does the Law of Requisite Variety say?', 'o' => ['More data always improves control', 'A regulator can only counter as much variety as it has responses for', 'Systems naturally become more varied', 'Every system needs a human in the loop'], 'a' => 1, 'why' => 'Only variety can destroy (absorb) variety. If the disturbances have more states than you have responses, some get through.'],
            ['q' => 'A support team is overwhelmed by many kinds of request. Which is an attenuation strategy?', 'o' => ['Hire and train more staff', 'Let front-line staff issue refunds themselves', 'Publish self-service answers to the ten most common questions', 'Add a second shift'], 'a' => 2, 'why' => 'Self-service reduces the variety reaching the team. The other three amplify the team\'s own variety. Both strategies are valid.'],
            ['q' => 'What does the Good Regulator Theorem imply for a business dashboard?', 'o' => ['More charts mean better control', 'Control can be no better than the model of the business the dashboard embodies', 'Dashboards should avoid models', 'Only real-time data is useful'], 'a' => 1, 'why' => 'A good regulator must be a model of what it regulates. A dashboard\'s definitions and structure are its model of the business.'],
        ],
        6 => [
            ['q' => 'A player checks every defensive against a fixed timing rule and never asks whether the rule fits the matchup. That is:', 'o' => ['Double-loop learning', 'Single-loop learning', 'Second-order cybernetics', 'A reinforcing loop'], 'a' => 1, 'why' => 'Correcting actions against a fixed standard is single-loop. Questioning whether the standard is right is double-loop.'],
            ['q' => 'In Boyd\'s OODA loop, which step did he consider most decisive?', 'o' => ['Observe', 'Orient', 'Decide', 'Act'], 'a' => 1, 'why' => 'Orientation, how you interpret what you observe through experience and models, shapes everything after it.'],
        ],
        7 => [
            ['q' => 'A call centre is rewarded for short calls. Calls get shorter and repeat calls rise. Which idea explains it best?', 'o' => ['Requisite variety', 'Goodhart\'s law', 'Limits to growth', 'Escalation'], 'a' => 1, 'why' => 'The measure became a target and stopped tracking what it was meant to: resolving the customer\'s problem.'],
            ['q' => 'A business keeps fixing late deliveries with express freight and never fixes its forecasting. Which trap is this?', 'o' => ['Shifting the burden', 'Success to the successful', 'Escalation', 'Tragedy of the commons'], 'a' => 0, 'why' => 'A symptomatic fix relieves the problem and reduces the pressure to apply the fundamental fix, which weakens over time.'],
        ],
        8 => [
            ['q' => 'According to Meadows, which of these is generally the highest-leverage place to intervene?', 'o' => ['Adjusting a numerical target', 'Changing the length of a delay', 'Changing the goal of the system', 'Increasing a buffer stock'], 'a' => 2, 'why' => 'Goals rank near the top (3rd of 12). Delays rank 9th, buffers 11th and parameters 12th.'],
            ['q' => 'Why do people so often intervene at the low-leverage end?', 'o' => ['Low-leverage changes usually work better', 'Numbers and parameters are visible and easy to change, even though they rarely alter behaviour much', 'High-leverage changes are forbidden', 'Meadows recommended starting there'], 'a' => 1, 'why' => 'Meadows observed that most attention and argument goes to parameters, though they rarely change how a system behaves.'],
        ],
    ],

    'archetypes' => [
        ['Fixes that fail', 'A quick fix relieves a symptom but has a delayed side effect that brings the problem back worse.', 'Closing jobs faster to cut the backlog raises repeat visits, which grow the backlog.', 'Look for the delayed side effect before applying the fix. Ask what it does in three months.'],
        ['Shifting the burden', 'A symptomatic fix is easier than the fundamental one, so it gets used every time, and the ability to do the real fix fades.', 'Paying for express freight instead of fixing forecasting. Carrying games on one teammate\'s cooldowns instead of fixing positioning.', 'Use the quick fix only to buy time, and protect the time and budget for the real one.'],
        ['Limits to growth', 'A reinforcing loop drives growth until it meets a balancing loop it can\'t overcome.', 'A service business grows on referrals until scheduling capacity caps it; marketing harder only lengthens the wait.', 'Find the limiting factor and work on it, rather than pushing the growth loop harder.'],
        ['Eroding goals', 'When performance falls short, the goal is lowered instead of the performance raised.', '"90% first-time fix" quietly becomes "80% is normal for us".', 'Anchor goals to something outside the system: customer needs, a benchmark, the best you have done.'],
        ['Escalation', 'Two parties each answer the other\'s action with more of the same, in a reinforcing loop.', 'Price wars. Teammates blaming each other harder after each lost round.', 'One side de-escalates on its own, or both agree on a different measure of winning.'],
        ['Success to the successful', 'Whoever does well early gets more resources, which makes them do better still, starving the alternative.', 'The highest-rated player on a team makes every call and the others stop developing.', 'Allocate on future potential as well as past results, and look at what the starved option could become.'],
    ],

    'leverage' => [
        [12, 'Constants, parameters, numbers', 'Subsidies, taxes, standards, a target value'],
        [11, 'The sizes of buffers and other stabilising stocks', 'Inventory, cash reserves, spare capacity'],
        [10, 'The structure of material stocks and flows', 'Roads, plant, physical layout'],
        [9, 'The lengths of delays, relative to the rate of system change', 'How long until you see the result of an action'],
        [8, 'The strength of balancing feedback loops', 'How strongly the system corrects itself'],
        [7, 'The gain around driving reinforcing loops', 'How fast a growth or decline loop runs'],
        [6, 'The structure of information flows', 'Who has access to what information'],
        [5, 'The rules of the system', 'Incentives, punishments, constraints'],
        [4, 'The power to add, change, evolve or self-organise system structure', 'Letting the system rewire itself'],
        [3, 'The goals of the system', 'What the whole thing is trying to achieve'],
        [2, 'The mindset or paradigm out of which the system arises', 'The shared assumptions behind the goals'],
        [1, 'The power to transcend paradigms', 'Holding no paradigm as the only true one'],
    ],

    'worksheet' => [
        'fields' => [
            'sys' => ['System', ''],
            'goal' => ['Set point', 'what "good" looks like, as a measurable target'],
            'sens' => ['Sensor', 'how you find out what actually happened'],
            'comp' => ['Comparison', 'when and how you compare the two'],
            'act' => ['Actuator', 'what you can actually change'],
            'delay' => ['Delay', 'time from action to seeing its effect'],
            'dist' => ['Disturbances', 'what pushes the system off course'],
            'game' => ['Goodhart check', 'how could the measure improve while the real thing gets worse?'],
            'dbl' => ['Double-loop question', 'what would make you change the goal itself?'],
        ],
        'example' => [
            'sys' => 'Example: my 3v3 arena games',
            'goal' => 'Use a major defensive in every enemy burst window, and survive 80% of them',
            'sens' => 'Combat log, reviewed per round: which bursts I had a defensive for, and what happened',
            'comp' => 'After each session, review three lost rounds against the goal',
            'act' => 'Keybinds, cooldown tracking, positioning before the enemy\'s go',
            'delay' => 'One session: I see the effect of a change the next evening',
            'dist' => 'Opponent comps, teammate changes, patch changes to cooldowns',
            'game' => 'I could press defensives early to "cover" every window and leave none for the real kill attempt',
            'dbl' => 'If I survive most bursts and still lose, the goal is wrong: the problem is elsewhere (damage, crowd control)',
        ],
    ],
];
