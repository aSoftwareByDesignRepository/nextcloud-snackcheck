<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Notification;

use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders SnackCheck in-app notifications (NH-08: a manager-recorded expense
 * credit is announced to the credited member — transparency beyond the digest).
 */
final class Notifier implements INotifier
{
	public const SUBJECT_EXPENSE_CREDIT = 'expense_credit';

	public function getID(): string
	{
		return 'snackcheck';
	}

	public function getName(): string
	{
		return 'SnackCheck';
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== 'snackcheck') {
			throw new UnknownNotificationException();
		}
		$l = \OCP\Util::getL10N('snackcheck', $languageCode);
		$params = array_merge(
			$notification->getSubjectParameters(),
			$notification->getMessageParameters(),
		);
		switch ($notification->getSubject()) {
			case self::SUBJECT_EXPENSE_CREDIT:
				$amount = (string)($params['amount'] ?? '?');
				$actor = (string)($params['actor'] ?? '');
				$notification->setParsedSubject(
					$l->t('Expense recorded for you'),
				);
				$notification->setParsedMessage(
					$actor !== ''
						? $l->t('%1$s recorded an expense of %2$s € on your snack tab.', [$actor, $amount])
						: $l->t('An expense of %1$s € was recorded on your snack tab.', [$amount]),
				);
				break;
			default:
				throw new UnknownNotificationException();
		}
		return $notification;
	}
}
