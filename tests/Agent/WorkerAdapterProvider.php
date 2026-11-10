<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AdapterProvider;
use Celyes\RalphPhp\Agent\ExecutionAdapter;
use Celyes\RalphPhp\Config\AgentDefinition;

/**
 * An adapter a project adds itself, written the way the documentation says to.
 *
 * {@see NamedAdapterProvider} registers a name and nothing more, which is all
 * the registry's own tests need. This one registers a *working* agent: its
 * adapter starts the configured executable in the task's workspace, hands it
 * the common briefing on standard input and reads the standard report back, so
 * an agent the package has never heard of can carry a task through the loop to
 * a commit — which is what PROJECT.md section E, item 3 promises and what
 * section J, item 3 asks to be tested.
 *
 * It is deliberately the whole of a third-party adapter: two methods, both
 * about this CLI's own invocation, with everything else inherited from
 * {@see ProcessAdapter}. Anything more here would be testing a fixture rather
 * than the extension point.
 *
 * Instantiated from a project's `.ralph/adapters.php`, which is why it is a
 * named class in its own file rather than an anonymous one inside a test.
 */
final class WorkerAdapterProvider implements AdapterProvider
{
    /**
     * @param string $adapter the name to register, as a project would choose it
     */
    public function __construct(private readonly string $adapter)
    {
    }

    public function adapter(): string
    {
        return $this->adapter;
    }

    public function create(AgentDefinition $definition): ExecutionAdapter
    {
        return new WorkerAdapter($this->adapter);
    }
}
