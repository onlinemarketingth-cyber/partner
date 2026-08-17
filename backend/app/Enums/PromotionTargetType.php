<?php

namespace App\Enums;

// Agent-view IA item 1.4 ("การเสนอ Promotion ให้ Agent") — who a
// targeted promotion campaign applies to. AllAgents = every agent in
// the company (or a single product's eligible agents, see
// AgentPromotion::product_id); CertTier = agents currently at a given
// cert tier; SpecificAgents = an explicit hand-picked list, stored in
// the agent_promotion_agent pivot table.
enum PromotionTargetType: string
{
    case AllAgents = 'all_agents';
    case CertTier = 'cert_tier';
    case SpecificAgents = 'specific_agents';
}
