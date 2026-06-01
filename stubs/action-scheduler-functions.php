<?php
/**
 * PHPStan stubs for the optional Action Scheduler dependency.
 *
 * Action Scheduler is an optional runtime dependency (the substrate detects it
 * and no-ops when absent). Its functions are not part of WordPress core stubs,
 * so they are stubbed here for static analysis.
 *
 * @see https://actionscheduler.org/
 */

/**
 * @param string               $hook  Hook name.
 * @param array<int, mixed>    $args  Arguments to pass to the hook.
 * @param string               $group Group to assign the action to.
 */
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {}

/**
 * @param int                  $timestamp When the first instance should run.
 * @param string               $schedule  Cron expression.
 * @param string               $hook      Hook name.
 * @param array<int, mixed>    $args      Arguments to pass to the hook.
 * @param string               $group     Group to assign the action to.
 */
function as_schedule_cron_action( int $timestamp, string $schedule, string $hook, array $args = array(), string $group = '' ): int {}

/**
 * @param int                  $timestamp        When the first instance should run.
 * @param int                  $interval_seconds How long to wait between runs.
 * @param string               $hook             Hook name.
 * @param array<int, mixed>    $args             Arguments to pass to the hook.
 * @param string               $group            Group to assign the action to.
 */
function as_schedule_recurring_action( int $timestamp, int $interval_seconds, string $hook, array $args = array(), string $group = '' ): int {}

/**
 * @param int                  $timestamp When the action should run.
 * @param string               $hook      Hook name.
 * @param array<int, mixed>    $args      Arguments to pass to the hook.
 * @param string               $group     Group to assign the action to.
 */
function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {}

/**
 * @param string            $hook  Hook name.
 * @param array<int, mixed> $args  Arguments that were passed when scheduling.
 * @param string            $group Group the action belongs to.
 */
function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ): int|bool {}

function as_has_scheduled_action( string $hook, ?array $args = null, string $group = '' ): bool {}
