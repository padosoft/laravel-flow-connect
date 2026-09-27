<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Fixtures\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortProvenance;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * A stand-in for a model completion or a fetched page: text an attacker can
 * influence, declared `Untrusted` so the taint analysis treats it as a source.
 */
#[FlowNode(type: 'test.untrusted_text', category: 'testing')]
final class UntrustedTextNode implements FlowNodeHandler
{
    #[Output(type: PortType::Text, provenance: PortProvenance::Untrusted)]
    public string $text;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success(['text' => 'orders']);
    }
}
