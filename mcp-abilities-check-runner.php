<?php
/**
 * Plugin Name: MCP Abilities - Check Runner
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-check-runner
 * Description: MCP bridge for the official WordPress.org Plugin Check plugin.
 * Version: 0.2.3
 * Author: basicus
 * Author URI: https://profiles.wordpress.org/basicus/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Requires Plugins: plugin-check
 *
 * @package MCP_Abilities_Plugin_Check
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if Abilities API is available.
 */
function mcp_check_runner_dependencies_ok(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p><strong>MCP Abilities - Check Runner</strong> requires WordPress core Abilities API support.</p></div>';
			}
		);
		return false;
	}

	return true;
}

/**
 * Flatten Plugin Check nested result arrays.
 *
 * @param array<string, mixed> $grouped Grouped errors or warnings.
 * @param string              $type Result type.
 * @return array<int, array<string, mixed>>
 */
function mcp_check_runner_flatten_results( array $grouped, string $type ): array {
	$flat = array();

	foreach ( $grouped as $file => $lines ) {
		if ( ! is_array( $lines ) ) {
			continue;
		}
		foreach ( $lines as $line => $columns ) {
			if ( ! is_array( $columns ) ) {
				continue;
			}
			foreach ( $columns as $column => $messages ) {
				if ( ! is_array( $messages ) ) {
					continue;
				}
				foreach ( $messages as $message ) {
					if ( ! is_array( $message ) ) {
						continue;
					}
					$flat[] = array(
						'type'     => $type,
						'file'     => (string) $file,
						'line'     => (int) $line,
						'column'   => (int) $column,
						'code'     => isset( $message['code'] ) ? (string) $message['code'] : '',
						'message'  => isset( $message['message'] ) ? wp_strip_all_tags( (string) $message['message'] ) : '',
						'docs'     => isset( $message['docs'] ) ? (string) $message['docs'] : '',
						'severity' => isset( $message['severity'] ) ? (int) $message['severity'] : 0,
					);
				}
			}
		}
	}

	return $flat;
}

/**
 * Run the official Plugin Check runner.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>
 */
function mcp_check_runner_run( array $input ): array {
	if ( ! class_exists( 'WordPress\\Plugin_Check\\Checker\\AJAX_Runner' ) ) {
		return array(
			'success' => false,
			'message' => 'The official WordPress.org Plugin Check plugin is not active or its runner class is unavailable.',
		);
	}

	$plugin = isset( $input['plugin'] ) ? sanitize_text_field( (string) $input['plugin'] ) : '';
	if ( '' === $plugin ) {
		return array(
			'success' => false,
			'message' => 'Plugin slug or basename is required.',
		);
	}
	if ( ! empty( $input['async'] ) ) {
		$job_id = 'pcr_' . wp_generate_password( 24, false, false );
		$job = array( 'job_id' => $job_id, 'status' => 'pending', 'plugin' => $plugin, 'created_at' => gmdate( 'c' ) );
		if ( ! add_option( 'mcp_check_runner_job_' . $job_id, $job, '', false ) ) {
			return array( 'success' => false, 'completed' => false, 'message' => 'Could not create the asynchronous Plugin Check job.' );
		}
		$scheduled_input = $input;
		$scheduled_input['async'] = false;
		if ( ! wp_schedule_single_event( time(), 'mcp_check_runner_async_job', array( $job_id, $scheduled_input ) ) ) {
			delete_option( 'mcp_check_runner_job_' . $job_id );
			return array( 'success' => false, 'completed' => false, 'message' => 'Could not schedule the asynchronous Plugin Check job.' );
		}
		spawn_cron();
		return array( 'success' => true, 'completed' => false, 'job_id' => $job_id, 'status' => 'pending', 'plugin' => $plugin, 'message' => 'Plugin Check scheduled in the WordPress background worker.' );
	}

	$include_experimental = true;
	$mode                 = isset( $input['mode'] ) && 'update' === $input['mode'] ? 'update' : 'new';
	$max_results          = isset( $input['max_results'] ) ? max( 1, min( 500, (int) $input['max_results'] ) ) : 100;

	$allow_plugin_check_fixture_publish = static function ( bool $allowed, array $context ): bool {
		return 'source_publish_design_gate' === (string) ( $context['guardrail'] ?? '' ) ? true : $allowed;
	};
	add_filter( 'devenia_workflow_allow_source_publish_design_gate_failure', $allow_plugin_check_fixture_publish, 10, 2 );
	$bounded_admin_memory = static function (): string {
		return '1G';
	};
	add_filter( 'admin_memory_limit', $bounded_admin_memory );
	wp_raise_memory_limit( 'admin' );

	try {
		$runner = new WordPress\Plugin_Check\Checker\AJAX_Runner();
		$runner->set_experimental_flag( $include_experimental );
		$runner->set_plugin( $plugin );
		$runner->set_slug( '' );
		$runner->set_mode( $mode );
		$runner->set_use_ai( false );

		$results = $runner->run();
	} catch ( Throwable $error ) {
		return array(
			'success' => false,
			'message' => $error->getMessage(),
		);
	} finally {
		remove_filter( 'devenia_workflow_allow_source_publish_design_gate_failure', $allow_plugin_check_fixture_publish, 10 );
		remove_filter( 'admin_memory_limit', $bounded_admin_memory );
	}

	$errors   = $results->get_errors();
	$warnings = $results->get_warnings();
	$flat     = array_merge(
		mcp_check_runner_flatten_results( $errors, 'error' ),
		mcp_check_runner_flatten_results( $warnings, 'warning' )
	);
	$error_count   = method_exists( $results, 'get_error_count' ) ? (int) $results->get_error_count() : count( mcp_check_runner_flatten_results( $errors, 'error' ) );
	$warning_count = method_exists( $results, 'get_warning_count' ) ? (int) $results->get_warning_count() : count( mcp_check_runner_flatten_results( $warnings, 'warning' ) );
	$passed        = 0 === $error_count && 0 === $warning_count;

	return array(
		'success'              => $passed,
		'completed'            => true,
		'passed'               => $passed,
		'policy'               => 'all-checks-zero-warnings',
		'plugin'               => $plugin,
		'mode'                 => $mode,
		'include_experimental' => $include_experimental,
		'checks'               => 'all',
		'categories'           => 'all',
		'error_count'          => $error_count,
		'warning_count'        => $warning_count,
		'results'              => array_slice( $flat, 0, $max_results ),
		'truncated'            => count( $flat ) > $max_results,
		'message'              => $passed
			? 'Plugin Check passed all checks with zero warnings.'
			: sprintf(
				'Plugin Check failed all-checks-zero-warnings policy with %d errors and %d warnings.',
				$error_count,
				$warning_count
			),
	);
}

/** Execute a scheduled Plugin Check job and persist its exact result. */
function mcp_check_runner_process_async_job( string $job_id, array $input ): void {
	$key = 'mcp_check_runner_job_' . sanitize_key( $job_id );
	$job = get_option( $key );
	if ( ! is_array( $job ) ) { return; }
	$job['status'] = 'running';
	$job['started_at'] = gmdate( 'c' );
	update_option( $key, $job, false );
	ignore_user_abort( true );
	register_shutdown_function(
		static function () use ( $key ): void {
			$current = get_option( $key );
			if ( ! is_array( $current ) || 'running' !== (string) ( $current['status'] ?? '' ) ) {
				return;
			}
			$last_error = error_get_last();
			$current['status'] = 'complete';
			$current['completed_at'] = gmdate( 'c' );
			$current['result'] = array(
				'success'   => false,
				'completed' => true,
				'passed'    => false,
				'code'      => 'async_job_terminated',
				'message'   => is_array( $last_error ) && ! empty( $last_error['message'] )
					? sanitize_text_field( (string) $last_error['message'] )
					: 'The asynchronous Plugin Check request terminated before returning a result.',
			);
			update_option( $key, $current, false );
		}
	);
	$result = mcp_check_runner_run( $input );
	$job['status'] = 'complete';
	$job['completed_at'] = gmdate( 'c' );
	$job['result'] = $result;
	update_option( $key, $job, false );
}
add_action( 'mcp_check_runner_async_job', 'mcp_check_runner_process_async_job', 10, 2 );

/** Return one server-owned asynchronous Plugin Check result. */
function mcp_check_runner_job_status( array $input ): array {
	$job_id = sanitize_key( (string) ( $input['job_id'] ?? '' ) );
	$job = $job_id ? get_option( 'mcp_check_runner_job_' . $job_id ) : false;
	return is_array( $job )
		? array( 'success' => true, 'job' => $job )
		: array( 'success' => false, 'message' => 'Plugin Check job not found.' );
}

/**
 * Register Plugin Check abilities.
 */
function mcp_register_check_runner_abilities(): void {
	if ( ! mcp_check_runner_dependencies_ok() ) {
		return;
	}

	wp_register_ability(
		'plugin-check/run',
		array(
			'label'               => 'Run WordPress.org Plugin Check',
			'description'         => 'Runs the official WordPress.org Plugin Check plugin against an installed plugin.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'plugin' ),
				'properties'           => array(
					'plugin'               => array( 'type' => 'string' ),
					'checks'               => array(
						'type'        => 'array',
						'description' => 'Deprecated and ignored. The ability always runs all available checks.',
						'items'       => array( 'type' => 'string' ),
						'default'     => array(),
					),
					'categories'           => array(
						'type'        => 'array',
						'description' => 'Deprecated and ignored. The ability always runs all check categories.',
						'items'       => array( 'type' => 'string' ),
						'default'     => array(),
					),
					'include_experimental' => array(
						'type'        => 'boolean',
						'description' => 'Deprecated and ignored. Experimental checks are always included.',
						'default'     => true,
					),
					'mode'                 => array(
						'type'    => 'string',
						'enum'    => array( 'new', 'update' ),
						'default' => 'new',
					),
					'max_results'          => array( 'type' => 'integer', 'default' => 100 ),
					'async'                => array( 'type' => 'boolean', 'default' => false, 'description' => 'Schedule the complete official check in a WordPress background request and return a job ID.' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'completed'     => array( 'type' => 'boolean' ),
					'job_id'        => array( 'type' => 'string' ),
					'status'        => array( 'type' => 'string' ),
					'passed'        => array( 'type' => 'boolean' ),
					'policy'        => array( 'type' => 'string' ),
					'plugin'        => array( 'type' => 'string' ),
					'mode'          => array( 'type' => 'string' ),
					'include_experimental' => array( 'type' => 'boolean' ),
					'checks'        => array( 'type' => 'string' ),
					'categories'    => array( 'type' => 'string' ),
					'error_count'   => array( 'type' => 'integer' ),
					'warning_count' => array( 'type' => 'integer' ),
					'results'       => array( 'type' => 'array' ),
					'truncated'     => array( 'type' => 'boolean' ),
					'message'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				return mcp_check_runner_run( $input );
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'activate_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'plugin-check/job-status',
		array(
			'label' => 'Get Plugin Check Job Status',
			'description' => 'Returns the exact result of one asynchronous official Plugin Check run.',
			'category' => 'site',
			'input_schema' => array( 'type' => 'object', 'required' => array( 'job_id' ), 'properties' => array( 'job_id' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
			'output_schema' => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'job' => array( 'type' => 'object' ), 'message' => array( 'type' => 'string' ) ) ),
			'execute_callback' => 'mcp_check_runner_job_status',
			'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ); },
			'meta' => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'mcp' => array( 'public' => true, 'type' => 'tool' ) ),
		)
	);
}
add_action( 'wp_abilities_api_init', 'mcp_register_check_runner_abilities' );
