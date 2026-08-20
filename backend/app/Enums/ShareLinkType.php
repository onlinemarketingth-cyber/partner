<?php

namespace App\Enums;

/**
 * TASK-212 — the kinds of link <ShareLinkModal> can email FOR THE SENDER,
 * through the platform SMTP row, instead of handing the job to whatever
 * mail client happens to be installed on their phone (human, 2026-08-19:
 * "ระบบ อีเมล์ให้ส่งผ่านระบบ").
 *
 * This enum is what makes that endpoint safe. The browser never sends a
 * URL — it sends a type and an id, and the server derives the URL from the
 * model it just authorized. An endpoint that accepted `{url, email}` would
 * be an open mail relay wearing this application's From: address: anyone
 * with an agent login could mail arbitrary links to arbitrary strangers,
 * signed by the company's own domain. Three named cases cannot do that.
 */
enum ShareLinkType: string
{
    case Order = 'order';
    case ProductShare = 'product_share';
    case AgentInvite = 'agent_invite';
}
