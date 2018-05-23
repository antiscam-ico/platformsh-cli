<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\IntegrationService;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationDeleteCommand extends CommandBase
{
    protected static $defaultName = 'integration:delete';

    private $activityMonitor;
    private $api;
    private $integrationService;
    private $questionHelper;
    private $selector;

    public function __construct(
        ActivityMonitor $activityMonitor,
        Api $api,
        IntegrationService $integration,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->activityMonitor = $activityMonitor;
        $this->api = $api;
        $this->integrationService = $integration;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The integration ID')
            ->setDescription('Delete an integration from a project');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->activityMonitor->addWaitOptions($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $id = $input->getArgument('id');

        $integration = $project->getIntegration($id);
        if (!$integration) {
            try {
                $integration = $this->api->matchPartialId($id, $project->getIntegrations(), 'Integration');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        $confirmText = sprintf('Delete the integration <info>%s</info> (type: %s)?', $integration->id, $integration->type);
        if (!$this->questionHelper->confirm($confirmText)) {
            return 1;
        }

        $result = $integration->delete();

        $this->stdErr->writeln(sprintf('Deleted integration <info>%s</info>', $integration->id));

        if ($this->activityMonitor->shouldWait($input)) {
            $this->activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
