<?php

namespace App\Enums;

// Agent-view IA item 1.6 ("การแจ้งข่าว/newsfeed"). Prototype scope is
// intentionally narrower than PromotionTargetType — no SpecificAgents
// case here. A per-agent-targeted newsfeed post was judged out of scope
// for this pass (see task spec doc); broadcast-to-tier is the finest
// grain shipped now.
enum AnnouncementAudience: string
{
    case AllAgents = 'all_agents';
    case CertTier = 'cert_tier';
}
