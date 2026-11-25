<?php

declare(strict_types=1);

namespace Drupal\Tests\content_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The tools appear as MCP tools once the MCP Tool API bridge is enabled.
 *
 * Skipped when drupal/mcp_server_tool_bridge is not installed: the bridge is
 * optional, the tools work without it.
 *
 * @group content_assistant
 */
#[Group('content_assistant')]
#[RunTestsInSeparateProcesses]
class McpBridgeTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'tool',
    'content_assistant',
  ];

  /**
   * The MCP tools for the four content assistant tools.
   */
  public function testToolsAreExposedOverMcp(): void {
    if (!class_exists('Drupal\mcp_server_tool_bridge\Entity\McpToolConfig')) {
      $this->markTestSkipped('drupal/mcp_server_tool_bridge is not installed.');
    }
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['content_assistant']);
    $this->container->get('module_installer')->install(['mcp_server_tool_bridge']);

    $definitions = $this->container->get('plugin.manager.mcp_server.tool')->getDefinitions();
    foreach (['collections', 'search', 'report', 'create_draft'] as $tool) {
      $this->assertArrayHasKey('tool_api__content_assistant_' . $tool, $definitions, $tool);
    }

    // The MCP tool keeps the Tool API permission.
    $this->createUser();
    $mcp = $this->container->get('plugin.manager.mcp_server.tool')->createInstance('tool_api__content_assistant_search');
    $this->assertFalse($mcp->checkAccess($this->createUser(['access content']))->isAllowed());
    $reader = $this->createUser(['access content', 'use content assistant']);
    $this->assertTrue($mcp->checkAccess($reader)->isAllowed());

    // A call as the reader returns the collections as structured content.
    $this->config('content_assistant.settings')->set('collections', [
      [
        'id' => 'pages',
        'label' => 'Pages',
        'description' => '',
        'bundle' => 'page',
        'date_field' => 'created',
        'group_field' => '',
        'search_fields' => [],
        'allow_drafts' => FALSE,
        'body_field' => '',
      ],
    ])->save();
    $this->container->get('current_user')->setAccount($reader);
    $gateway = $this->createMock('Mcp\Server\ClientGateway');
    $result = $this->container->get('plugin.manager.mcp_server.tool')
      ->createInstance('tool_api__content_assistant_collections')
      ->execute([], $gateway);
    $this->assertFalse($result->isError, json_encode($result->content));
    $this->assertSame('pages', $result->structuredContent['collections'][0]['collection']);
  }

}
