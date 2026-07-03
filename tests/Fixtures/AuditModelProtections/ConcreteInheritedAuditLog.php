<?php

declare(strict_types = 1);

namespace App\Models\Audit;

/**
 * A concrete audit model that declares NO traits of its own but inherits
 * HasFactory from the abstract `AbstractAuditBase`. The transitive trait walk
 * must catch the inherited HasFactory and fire
 * enforceAuditModelProtections.hasFactoryForbidden at this leaf — the abstract
 * base carrying the trait is exempt, so the concrete leaf is where the
 * violation lands.
 */
final class ConcreteInheritedAuditLog extends AbstractAuditBase {}
