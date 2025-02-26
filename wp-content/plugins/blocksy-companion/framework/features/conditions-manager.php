<?php

namespace Blocksy;

class ConditionsManager {
	public function __construct() {
	}

	public function condition_matches($rules_param = [], $args = []) {
		$args = wp_parse_args($args, [
			// prefix | current-screen
			'strategy' => 'current-screen',

			// global | archive-loop
			'conditions_purpose' => 'global'
		]);

		$rules = $this->normalize_rules($rules_param, $args);

		if (! isset($rules['conditions']) || empty($rules['conditions'])) {
			if ($args['conditions_purpose'] === 'archive-loop') {
				return true;
			}

			return false;
		}

		$all_includes = array_filter($rules['conditions'], function ($el) {
			if (
				! isset($el['type'])
				&&
				isset($el['conditions'])
			) {
				return true;
			}

			return $el['type'] === 'include';
		});

		$all_excludes = array_filter($rules['conditions'], function ($el) {
			if (! isset($el['type']) && isset($el['conditions'])) {
				return false;
			}

			return $el['type'] === 'exclude';
		});

		$resolved_includes = array_filter(
			$all_includes,
			function ($el) use ($args) {
				if (isset($el['conditions'])) {
					return $this->condition_matches($el, $args);
				}

				$resolver = new ConditionsRulesResolver($el, $args);

				return $resolver->resolve();
			}
		);

		$resolved_excludes = array_filter(
			$all_excludes,
			function ($el) use ($args) {
				$resolver = new ConditionsRulesResolver($el, $args);

				return $resolver->resolve();
			}
		);

		if ($rules['relation'] === 'AND') {
			// If we have at least one exclude and ALL exclusions are true -- return false
			if (
				! empty($all_excludes)
				&&
				count($all_excludes) === count($resolved_excludes)
			) {
				return false;
			}

			if (empty($all_includes)) {
				return true;
			}

			if (
				! empty($all_includes)
				&&
				count($resolved_includes) === count($all_includes)
			) {
				return true;
			}
		}

		if ($rules['relation'] === 'OR') {
			// If AT LEAST ONE exclusion is true -- return false
			if (! empty($resolved_excludes)) {
				return false;
			}

			if (empty($all_includes)) {
				return true;
			}

			if (! empty($resolved_includes)) {
				return true;
			}
		}

		return false;
	}

	public function get_all_rules($args = []) {
		$args = wp_parse_args($args, [
			// all | archive | singular | product_tabs | maintenance-mode | content_block_hook
			'filter' => 'all',

			// frontend | backend
			'context' => 'frontend'
		]);

		$rules = [];

		$sections = [
			'basic',
			'posts',
			'pages',
			'woo',
			'cpt',
			'specific'
		];

		if ($args['context'] === 'backend') {
			$sections[] = 'archive-loop';
		}

		if ($args['filter'] === 'content_block_hook') {
			$sections[] = 'archive-loop';
			$args['filter'] = 'all';
		}

		if (
			$args['filter'] === 'all'
			||
			$args['filter'] === 'product_tabs'
		) {
			$sections[] = 'user-auth';
		}
		
		$sections[] = 'date-time';
		$sections[] = 'requests';
		$sections[] = 'localization';
		$sections[] = 'bbPress';
		$sections[] = 'custom';

		if ($args['filter'] === 'product_waitlist') {
			$sections = [
				'basic',
				'woo',
				'requests',
				'localization',
				'custom'
			];
		}

		if ($args['filter'] === 'archive' || $args['filter'] === 'singular') {
			$sections = [
				'basic',
				'posts',
				'pages',
				'cpt',
				'specific',
				'user-auth',
				'date-time',
				'localization',
				'custom'
			];
		}

		if ($args['filter'] === 'maintenance-mode') {
			$sections = [
				'basic',
				'user-auth',
				'date-time',
				'localization',
				'custom'
			];
		}

		foreach ($sections as $section) {
			$maybe_rules = blocksy_get_options(
				dirname(__FILE__) . '/conditions/rules/' . $section . '.php',
				['filter' => $args['filter']],
				false
			);

			if ($maybe_rules) {
				$rules = array_merge($rules, $maybe_rules);
			}
		}

		return $rules;
	}

	public function humanize_conditions($conditions) {
		if (isset($conditions['conditions'])) {
			$conditions = $conditions['conditions'];
		}

		// Check if it looks like a normal rules array. If it doesn't -- bail out.
		if (! isset($conditions[0])) {
			return [];
		}

		$result = [];

		$conditions = $this->normalize_rules($conditions, [
			'conditions_purpose' => 'all'
		]);

		$has_and = false;

		foreach ($conditions['conditions'] as $condition) {
			if (
				isset($condition['relation'])
				&&
				$condition['relation'] === 'AND'
			) {
				$has_and = true;
				break;
			}
		}

		foreach ($conditions['conditions'] as $index => $condition) {
			if (isset($condition['conditions'])) {
				$result = array_merge(
					$result,
					$this->humanize_conditions($condition)
				);

				if (
					$conditions['relation'] === 'OR'
					&&
					$index !== count($conditions['conditions']) - 1
				) {
					$result[] = 'OR';
				}

				continue;
			}

			$type = $condition['type'] === 'include' ? __('Include', 'blocksy-companion') : __(
				'Exclude', 'blocksy-companion'
			);

			$maybe_descriptor = $this->find_rule_descriptor($condition['rule']);

			if (! $maybe_descriptor) {
				continue;
			}

			$to_append = $type . ' ' . $maybe_descriptor['title'];

			if (
				(
					$condition['rule'] === 'post_ids'
					||
					$condition['rule'] === 'page_ids'
					||
					$condition['rule'] === 'product_ids'
					||
					$condition['rule'] === 'custom_post_type_ids'
				) && isset($condition['payload']['post_id'])
			) {
				$to_append .= ' (<a href="' . get_edit_post_link(
					$condition['payload']['post_id']
				) . '" target="_blank">' . get_the_title($condition['payload']['post_id']) . '</a>)';
			}

			if (
				(
					$condition['rule'] === 'taxonomy_ids'
					||
					$condition['rule'] === 'post_with_taxonomy_ids'
					||
					$condition['rule'] === 'product_with_taxonomy_ids'
					||
					$condition['rule'] === 'card_product_with_taxonomy_ids'
					||
					$condition['rule'] === 'card_post_with_taxonomy_ids'
					||
					$condition['rule'] === 'product_taxonomy_ids'
				) && isset($condition['payload']['taxonomy_id'])
			) {
				$tax = get_term_by(
					'term_taxonomy_id',
					$condition['payload']['taxonomy_id']
				);

				if ($tax && ! is_wp_error($tax)) {
					$to_append .= ' (<a href="' . get_edit_term_link(
						$condition['payload']['taxonomy_id']
					) . '" target="_blank">' . $tax->name . '</a>)';
				}
			}

			if ($condition['rule'] === 'current_language') {
				$to_append = null;

				if (
					isset($condition['payload']['language'])
					&&
					function_exists('blocksy_get_all_i18n_languages')
				) {
					foreach (blocksy_get_all_i18n_languages() as $lang) {
						if ($lang['id'] === $condition['payload']['language']) {
							$to_append = $type . ' ' . $lang['name'] . ' ' . __(
								'Language', 'blocksy-companion'
							);
						}
					}
				}
			}

			if ($to_append) {
				$result[] = $to_append;

				if (
					$conditions['relation'] === 'AND'
					&&
					$index !== count($conditions['conditions']) - 1
				) {
					$result[] = 'AND';
				}

				if (
					$has_and
					&&
					$conditions['relation'] === 'OR'
					&&
					$index !== count($conditions['conditions']) - 1
				) {
					$result[] = 'OR';
				}
			}
		}

		return $result;
	}

	private function find_rule_descriptor($rule) {
		$all = $this->get_all_rules([
			'context' => 'backend'
		]);

		$user_roles = $this->get_user_roles_rules();

		foreach ($all as $rules_group) {
			foreach ($rules_group['rules'] as $single_rule) {
				if ($single_rule['id'] === $rule) {
					return $single_rule;
				}
			}
		}

		foreach ($user_roles as $single_rule) {
			if ($single_rule['id'] === $rule) {
				return $single_rule;
			}
		}

		return null;
	}

	private function get_user_roles_rules() {
		$result = [];

		foreach (get_editable_roles() as $role_id => $role_info) {
			$result[] = [
				'id' => 'user_role_' . $role_id,
				'title' => $role_info['name']
			];
		}

		return $result;
	}

	private function normalize_rules($rules = [], $args = []) {
		$args = wp_parse_args($args, [
			// all | global | archive-loop
			'conditions_purpose' => 'global'
		]);

		$normalized = $rules;

		if (! isset($rules['relation'])) {
			$normalized = [
				'relation' => 'OR',
				'conditions' => $rules
			];
		}

		$normalized['conditions'] = array_filter(
			$normalized['conditions'],
			function ($condition) use ($args) {
				if ($args['conditions_purpose'] === 'all') {
					return true;
				}

				if ($args['conditions_purpose'] === 'archive-loop') {
					return $this->is_card_rule($condition);
				}

				return true;
			}
		);

		return $normalized;
	}

	public function is_card_rule($rule) {
		$resolver = new ConditionsRulesResolver($rule, [
			'conditions_purpose' => 'archive-loop'
		]);

		// Allow further inspection of nested rules
		if (isset($rule['conditions'])) {
			return true;
		}

		if (
			$rule['rule'] === 'card_product_with_taxonomy_ids'
			&&
			$resolver->is_woo_archive()
		) {
			return true;
		}

		if (
			$rule['rule'] === 'card_post_with_taxonomy_ids'
			&&
			$resolver->is_cpt_archive()
		) {
			return true;
		}

		return false;
	}
}

