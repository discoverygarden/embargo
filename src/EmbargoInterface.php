<?php

namespace Drupal\embargo;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Provides an interface for defining Embargo entities.
 */
interface EmbargoInterface extends ContentEntityInterface {

  // Constants for embargo types.
  const EMBARGO_TYPE_FILE = 0;
  const EMBARGO_TYPE_NODE = 1;

  /**
   * Properties of each embargo type.
   *
   * @var array
   *   An associative array mapping types to their labels.
   */
  const EMBARGO_TYPES = [
    self::EMBARGO_TYPE_FILE => 'File',
    self::EMBARGO_TYPE_NODE => 'Node',
  ];

  // Constants for expiration types.
  const EXPIRATION_TYPE_INDEFINITE = 0;
  const EXPIRATION_TYPE_SCHEDULED = 1;

  /**
   * Properties of each embargo type.
   *
   * @var array
   *   An associative array mapping types to their labels.
   */
  const EXPIRATION_TYPES = [
    self::EXPIRATION_TYPE_INDEFINITE => 'Indefinite',
    self::EXPIRATION_TYPE_SCHEDULED => 'Scheduled',
  ];

  /**
   * Returns the type of embargo.
   *
   * @return int
   *   The type of the embargo.
   */
  public function getEmbargoType(): int;

  /**
   * Sets the embargo type.
   *
   * @param int $type
   *   The type of embargo.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   If $type is not valid.
   */
  public function setEmbargoType(int $type): EmbargoInterface;

  /**
   * Defines allowed embargo types.
   *
   * @return int[]
   *   The allowed embargo types.
   */
  public static function getAllowedEmbargoTypes(): array;

  /**
   * Gets the label for the embargoes type.
   *
   * @return string
   *   The human readable label for the embargoes type.
   */
  public function getEmbargoTypeLabel(): string;

  /**
   * Gets an array of embargo types map to their respective labels.
   *
   * @return string[]
   *   An array of embargo types map to their respective labels.
   */
  public static function getEmbargoTypeLabels(): array;

  /**
   * Returns the expiration type of embargo.
   *
   * @return int
   *   The expiration type of the embargo.
   */
  public function getExpirationType(): int;

  /**
   * Sets the expiration type.
   *
   * @param int $type
   *   The expiration type of embargo.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   If $type is not valid.
   */
  public function setExpirationType(int $type): EmbargoInterface;

  /**
   * Defines allowed expiration types.
   *
   * @return int[]
   *   The allowed expiration types.
   */
  public static function getAllowedExpirationTypes(): array;

  /**
   * Gets an array of expiration types mapped to their respective labels.
   *
   * @return string[]
   *   An array of expiration types mapped to their respective labels.
   */
  public static function getExpirationTypeLabels(): array;

  /**
   * Gets the timestamp for when the check was performed.
   *
   * @return \Drupal\Core\Datetime\DrupalDateTime|null
   *   The expiration date or NULL.
   */
  public function getExpirationDate(): ?DrupalDateTime;

  /**
   * Sets the date of expiration for a scheduled embargo.
   *
   * @param \Drupal\Core\Datetime\DrupalDateTime|null $date
   *   The expiration date or NULL.
   *
   * @return $this
   */
  public function setExpirationDate(?DrupalDateTime $date): EmbargoInterface;

  /**
   * Gets the exempt embargo_ip_range entity for this embargo if set.
   *
   * @return \Drupal\embargo\IpRangeInterface|null
   *   The exempt embargo_ip_range entity, or NULL.
   */
  public function getExemptIps(): ?IpRangeInterface;

  /**
   * Sets the exempt embargo_ip_range entity for this embargo.
   *
   * @param IpRangeInterface|null $range
   *   The embargo_ip_range entity, to exempt from the embargo.
   *
   * @return $this
   */
  public function setExemptIps(?IpRangeInterface $range): EmbargoInterface;

  /**
   * Gets the list of exempt users.
   *
   * @return \Drupal\user\UserInterface[]
   *   A list of user exempt from the embargo.
   */
  public function getExemptUsers(): array;

  /**
   * Sets the list of users exempt from this embargo.
   *
   * @param array $users
   *   A list of user entities to exempt from this embargo.
   *
   * @return $this
   */
  public function setExemptUsers(array $users): EmbargoInterface;

  /**
   * Retrieves the node being embargoed.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node being embargoed or NULL if it is not yet been set.
   */
  public function getEmbargoedNode(): ?NodeInterface;

  /**
   * Sets the node this embargo applies to.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to embargo.
   *
   * @return $this
   */
  public function setEmbargoedNode(NodeInterface $node): EmbargoInterface;

  /**
   * An array of email addresses to be notified in regards to the embargo.
   *
   * @return string[]
   *   An array of email addresses.
   */
  public function getAdditionalEmails(): array;

  /**
   * Sets the list of email addresses to be notified in regards to the embargo.
   *
   * @param string[] $emails
   *   A list of email addresses to be notified in regards to the embargo.
   *
   * @return $this
   */
  public function setAdditionalEmails(array $emails): ?EmbargoInterface;

  /**
   * Checks if the embargo expires before the given date.
   *
   * If the embargo is of type indefinite this function always returns FALSE.
   *
   * @param int $date
   *   UNIX timestamp to check against.
   *
   * @return bool
   *   TRUE if the embargo expires before the given date, FALSE otherwise.
   */
  public function expiresBefore(int $date): bool;

  /**
   * Checks if the given user is exempt from this embargo.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user to check.
   *
   * @return bool
   *   TRUE if the user is exempt otherwise FALSE.
   */
  public function isUserExempt(AccountInterface $user): bool;

  /**
   * Checks if the given IP address is exempt from this embargo.
   *
   * @param string $ip
   *   Either an IPv4 or IPv6 address to check.
   *
   * @return bool
   *   TRUE if the IP address is exempt otherwise FALSE.
   */
  public function ipIsExempt(string $ip): bool;

}
