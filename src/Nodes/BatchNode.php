<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Nodes;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowConnect\Exceptions\MappingException;

/**
 * Splits a list into fixed-size batches, in order: `items` `[1,2,3,4,5]` with
 * `size` 2 gives `batches` `[[1,2],[3,4],[5]]` and `count` 3. The last batch may
 * be shorter; an empty list gives no batches. Pair it with `flow.foreach` to
 * process each batch (e.g. one API call per 100 records) instead of one item at a
 * time.
 *
 * `items` must be a LIST (not an object) and `size` a positive integer no larger
 * than `laravel-flow-connect.batch.max_size` (default 1000), so a bad value
 * cannot produce a single giant batch by accident. Pure, so it runs in a dry run;
 * the output is `Derived` (untrusted in, untrusted out).
 *
 * @api
 */
#[FlowNode(type: 'connect.batch', category: 'connect', description: 'Splits a list into fixed-size batches.')]
final class BatchNode implements FlowNodeHandler
{
    public const DEFAULT_MAX_SIZE = 1000;

    /** @var list<mixed> */
    #[Input(type: PortType::Json, required: true)]
    public array $items;

    #[Input(type: PortType::Int, required: true)]
    public int $size;

    /** @var list<list<mixed>> */
    #[Output(type: PortType::Json)]
    public array $batches;

    #[Output(type: PortType::Int)]
    public int $count;

    public function __construct(private readonly ConfigRepository $config) {}

    public function execute(NodeContext $context): NodeResult
    {
        $items = $context->inputs['items'] ?? null;
        $size = $context->inputs['size'] ?? null;
        $max = $this->maxSize();

        try {
            if (! is_array($items) || ! array_is_list($items)) {
                throw new MappingException('`items` must be a list.');
            }

            if (! is_int($size) || $size < 1) {
                throw new MappingException('`size` must be a positive integer.');
            }

            if ($size > $max) {
                throw new MappingException(sprintf('`size` exceeds the %d limit (laravel-flow-connect.batch.max_size).', $max));
            }
        } catch (MappingException $e) {
            return NodeResult::failed($e);
        }

        $batches = array_chunk($items, $size);

        return NodeResult::success(['batches' => $batches, 'count' => count($batches)]);
    }

    private function maxSize(): int
    {
        $max = $this->config->get('laravel-flow-connect.batch.max_size', self::DEFAULT_MAX_SIZE);

        return is_numeric($max) && (int) $max >= 1 ? (int) $max : self::DEFAULT_MAX_SIZE;
    }
}
