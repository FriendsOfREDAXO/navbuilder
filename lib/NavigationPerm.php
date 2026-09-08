<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\NavBuilder;

use rex;
use rex_complex_perm;
use rex_i18n;
use rex_sql;

use function in_array;
use function is_array;

/**
 * Complex permission `navbuilder`: which navigations a role may edit (issue #16, multisite).
 *
 * Two layers on top of the page permission `navbuilder[]`:
 *
 *  - the option permission `navbuilder[manage]` grants full control — creating, duplicating
 *    and deleting navigations plus editing every one of them (admins implicitly have it)
 *  - without it, this complex permission decides which navigations are editable; its
 *    "all" choice grants editing everything while still withholding create/delete
 *
 * The selection is stored by navigation **id**, so renaming a navigation cannot orphan a
 * role's permission. {@see Navigation::delete()} removes deleted ids from all roles via
 * {@see rex_complex_perm::removeItem()}.
 *
 * @api projects may check these permissions in their own extension code
 */
final class NavigationPerm extends rex_complex_perm
{
	public function hasPerm(int $id): bool
	{
		return $this->hasAll() || (is_array($this->perms) && in_array($id, array_map(intval(...), $this->perms), true));
	}

	/** Create, duplicate, delete — and edit everything. */
	public static function mayManage(): bool
	{
		$user = rex::getUser();

		return null !== $user && (true === $user->isAdmin() || true === $user->hasPerm('navbuilder[manage]'));
	}

	public static function mayEdit(int $id): bool
	{
		if (self::mayManage()) {
			return true;
		}

		$perm = rex::getUser()?->getComplexPerm('navbuilder');

		return $perm instanceof self && $perm->hasPerm($id);
	}

	/**
	 * The ids the current user may edit — `null` means "unrestricted" (manage/all), so the
	 * caller can skip filtering instead of enumerating every navigation.
	 *
	 * @return list<int>|null
	 */
	public static function allowedIds(): ?array
	{
		if (self::mayManage()) {
			return null;
		}

		$perm = rex::getUser()?->getComplexPerm('navbuilder');

		if (!$perm instanceof self) {
			return [];
		}

		if ($perm->hasAll()) {
			return null;
		}

		return is_array($perm->perms) ? array_values(array_map(intval(...), $perm->perms)) : [];
	}

	/**
	 * @return array{label: string, all_label: string, options: array<int, string>}
	 */
	public static function getFieldParams(): array
	{
		$options = [];

		foreach (rex_sql::factory()->getArray('SELECT `id`, `name` FROM ' . Navigation::table() . ' ORDER BY `name`') as $row) {
			$options[(int) $row['id']] = (string) $row['name'];
		}

		return [
			'label' => rex_i18n::msg('navbuilder_perm_navigations'),
			'all_label' => rex_i18n::msg('navbuilder_perm_all_navigations'),
			'options' => $options,
		];
	}
}
