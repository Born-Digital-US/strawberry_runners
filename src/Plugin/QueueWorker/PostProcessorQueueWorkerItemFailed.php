<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 01/31/2023
 * Time: 11:07 AM
 */

namespace Drupal\strawberry_runners\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * This queue as a container for inspecting failed strawberry runner process items.
 *
 * @QueueWorker(
 *   id = "strawberryrunners_process_item_failed",
 *   title = @Translation("Failed Strawberry Runner Items for Review"),
 * )
 */
class PostProcessorQueueWorkerItemFailed extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Drupal\Core\Entity\EntityTypeManager definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * @var \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager
   */
  private $strawberryRunnerProcessorPluginManager;

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Constructor.
   *
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager
   * @param \Psr\Log\LoggerInterface $logger
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager, LoggerInterface $logger, QueueFactory $queue_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->strawberryRunnerProcessorPluginManager = $strawberry_runner_processor_plugin_manager;
    $this->logger = $logger;
    $this->queueFactory = $queue_factory;
  }
  /**
   * Implementation of the container interface to allow dependency injection.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      empty($configuration) ? [] : $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('strawberry_runner.processor_manager'),
      $container->get('logger.channel.strawberry_runners'),
      $container->get('queue'),
    );
  }

  /**
   * Processing an item simply removes it from the queue.
   *
   */
  public function processItem($data) {
    $processor_instance = $this->getProcessorPlugin($data->plugin_config_entity_id);
    if ($processor_instance) {
      $message_params = [
        '@processor' => $processor_instance->getPluginId(),
        '@queue'     => $this->getBaseId(),
      ];
      $this->logger->info(
        'Moving queue item from @queue with @processor back to configured queues.',
        $message_params
      );
      $processor_config = $processor_instance->getConfiguration();
      $data->extract_attempts = 0;
      if (($processor_config['processor_queue_type'] ?? 'realtime')
        == 'background'
      ) {
        $this->queueFactory->get(
          'strawberryrunners_process_background', TRUE
        )->createItem($data);
      }
      else {
        $this->queueFactory->get(
          'strawberryrunners_process_index', TRUE
        )->createItem($data);
      }
    }
    else {
      $this->logger->info(
        'Original processor is disabled so queue item will be removed'
      );
    }
  }

  /**
   * Get the extractor plugin.
   *
   * @param $plugin_config_entity_id
   *
   * @return StrawberryRunnersPostProcessorPluginInterface|NULL
   *   The plugin.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getProcessorPlugin($plugin_config_entity_id) {
    // Get extractor configuration.
    /* @var $plugin_config_entity \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntityInterface */
    $plugin_config_entity = $this->entityTypeManager
      ->getStorage('strawberry_runners_postprocessor')
      ->load($plugin_config_entity_id);

    if ($plugin_config_entity->isActive()) {
      $entity_id = $plugin_config_entity->id();
      $configuration_options = $plugin_config_entity->getPluginconfig();
      $configuration_options['configEntity'] = $entity_id;
      /* @var \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface $plugin_instance */
      $plugin_instance = $this->strawberryRunnerProcessorPluginManager->createInstance(
        $plugin_config_entity->getPluginid(),
        $configuration_options
      );
      return $plugin_instance;
    }
    return NULL;
  }

}
