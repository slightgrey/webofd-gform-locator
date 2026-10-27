<?php
/**
 * Registers all actions and filters for the plugin.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator;

/**
 * Collects actions and filters then registers them with WordPress on run().
 */
class Loader {

	/**
	 * Queued action hooks.
	 *
	 * @var array<int, array{hook: string, callback: callable, priority: int, accepted_args: int}>
	 */
	private array $actions = array();

	/**
	 * Queued filter hooks.
	 *
	 * @var array<int, array{hook: string, callback: callable, priority: int, accepted_args: int}>
	 */
	private array $filters = array();

	/**
	 * Queues an action hook.
	 *
	 * @param string   $hook          The WordPress action hook name.
	 * @param callable $callback      The callback to run.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Number of arguments the callback accepts.
	 *
	 * @return void
	 */
	public function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->actions[] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Queues a filter hook.
	 *
	 * @param string   $hook          The WordPress filter hook name.
	 * @param callable $callback      The callback to run.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Number of arguments the callback accepts.
	 *
	 * @return void
	 */
	public function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->filters[] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Registers all queued actions and filters with WordPress.
	 *
	 * @return void
	 */
	public function run(): void {
		foreach ( $this->actions as $action ) {
			add_action( $action['hook'], $action['callback'], $action['priority'], $action['accepted_args'] );
		}

		foreach ( $this->filters as $filter ) {
			add_filter( $filter['hook'], $filter['callback'], $filter['priority'], $filter['accepted_args'] );
		}
	}
}
