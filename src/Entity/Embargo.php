<?php

namespace Drupal\embargo\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\embargo\IpRangeInterface;
use Drupal\node\NodeInterface;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * Defines the Embargo entity.
 *
 * @ContentEntityType(
 *   id = "embargo",
 *   label = @Translation("Embargo"),
 *   label_collection = @Translation("Embargoes"),
 *   label_singular = @Translation("Embargo"),
 *   label_plural = @Translation("Embargoes"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Embargo",
 *     plural = "@count Embargoes"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\embargo\EmbargoStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\embargo\EmbargoListBuilder",
 *     "form" = {
 *       "add" = "Drupal\embargo\Form\EmbargoForm",
 *       "edit" = "Drupal\embargo\Form\EmbargoForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     },
 *   },
 *   list_cache_tags = { "node_list", "media_list", "file_list" },
 *   base_table = "embargo",
 *   admin_permission = "administer embargo",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "canonical" = "/embargo/{embargo}/edit",
 *     "add-form" = "/embargo/add",
 *     "edit-form" = "/embargo/{embargo}/edit",
 *     "delete-form" = "/embargo/{embargo}/delete",
 *     "embargo-node-form" = "/embargo/node/{node}/add",
 *     "collection" = "/admin/content/embargo"
 *   },
 *   constraints = {
 *     "ScheduledEmbargoDateProvided" = {}
 *   }
 * )
 */
class Embargo extends ContentEntityBase implements EmbargoInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['embargo_type'] = BaseFieldDefinition::create('list_integer')
      ->setLabel(t('Embargo Type'))
      ->setDescription(t('A <em>File</em> embargo denies access to the nodes related files and media.<br/>A <em>Node</em> embargo denies all access to the node as well as its related files and media.'))
      ->setInitialValue(EmbargoInterface::EMBARGO_TYPE_FILE)
      ->setDefaultValue(EmbargoInterface::EMBARGO_TYPE_FILE)
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(TRUE)
      // Define this via an options provider once.
      // https://www.drupal.org/node/2329937 is completed.
      ->addPropertyConstraints('value', [
        'AllowedValues' => [
          'callback' => [static::class, 'getAllowedEmbargoTypes'],
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'label' => 'hidden',
      ])
      ->setSetting('allowed_values', static::getEmbargoTypeLabels());

    $fields['expiration_type'] = BaseFieldDefinition::create('list_integer')
      ->setLabel(t('Expiration Type'))
      ->setDescription(t('A <em>Indefinite</em> embargo is never lifted.<br/>A <em>Scheduled</em> embargo is lifted on the specified date.'))
      ->setInitialValue(EmbargoInterface::EXPIRATION_TYPE_INDEFINITE)
      ->setDefaultValue(EmbargoInterface::EXPIRATION_TYPE_INDEFINITE)
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(TRUE)
      // Define this via an options provider once.
      // https://www.drupal.org/node/2329937 is completed.
      ->addPropertyConstraints('value', [
        'AllowedValues' => [
          'callback' => [static::class, 'getAllowedExpirationTypes'],
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'label' => 'hidden',
      ])
      ->setSetting('allowed_values', static::getExpirationTypeLabels());

    $fields['expiration_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Expiration Date'))
      ->setDescription(t('The date when the embargo will be lifted.<br/>Only applies when <strong>Expiration Type</strong> is set to <em>Scheduled</em>.'))
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
      ])
      ->setDisplayOptions('view', [
        'type' => 'datetime_time_ago',
        'label' => 'hidden',
      ])
      ->setSetting('datetime_type', 'date');

    $fields['exempt_ips'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Exempt IP Range'))
      ->setDescription(t('Users connecting from an IP within this set of ranges will be able to bypass the embargo.'))
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'label' => 'hidden',
      ])
      ->setSettings([
        'target_type' => 'embargo_ip_range',
      ]);

    $fields['exempt_users'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('List of exempt users'))
      ->setDescription(t('These users will be able to bypass the embargo.'))
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'label' => 'hidden',
      ])
      ->setSettings([
        'target_type' => 'user',
        'handler_settings' => [
          'include_anonymous' => FALSE,
        ],
      ]);

    $fields['exempt_roles'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('List of exempt roles'))
      ->setDescription(t('These roles will be able to bypass the embargo.'))
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'label' => 'hidden',
      ])
      ->setSettings([
        'target_type' => 'user_role',
        'handler_settings' => [
          'include_anonymous' => FALSE,
        ],
      ]);

    $fields['additional_emails'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Additional Emails'))
      ->setDescription(t('For contact changes to this embargo.'))
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', [
        'type' => 'email_default',
      ])
      ->setDisplayOptions('view', [
        'type' => 'email_mailto',
        'label' => 'hidden',
      ]);

    $fields['embargoed_node'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Embargoed Node'))
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -1,
        'settings' => [
          'size' => 60,
        ],
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'label' => 'hidden',
      ])
      ->setSettings([
        'target_type' => 'node',
      ]);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getEmbargoType(): int {
    return $this->get('embargo_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setEmbargoType(int $type): EmbargoInterface {
    if (!in_array($type, static::getAllowedEmbargoTypes())) {
      throw new \InvalidArgumentException("Invalid Embargo type '$type' has been given");
    }
    $this->set('embargo_type', $type);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function getAllowedEmbargoTypes(): array {
    return array_keys(static::EMBARGO_TYPES);
  }

  /**
   * {@inheritdoc}
   */
  public function getEmbargoTypeLabel(): string {
    return static::EMBARGO_TYPES[$this->getEmbargoType()];
  }

  /**
   * {@inheritdoc}
   */
  public static function getEmbargoTypeLabels(): array {
    return EmbargoInterface::EMBARGO_TYPES;
  }

  /**
   * {@inheritdoc}
   */
  public function getExpirationType(): int {
    return $this->get('expiration_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setExpirationType(int $type): EmbargoInterface {
    if (!in_array($type, static::getAllowedExpirationTypes())) {
      throw new \InvalidArgumentException("Invalid Embargo expiration type '$type' has been given");
    }
    $this->set('expiration_type', $type);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function getAllowedExpirationTypes(): array {
    return array_keys(static::EXPIRATION_TYPES);
  }

  /**
   * {@inheritdoc}
   */
  public static function getExpirationTypeLabels(): array {
    return EmbargoInterface::EXPIRATION_TYPES;
  }

  /**
   * {@inheritdoc}
   */
  public function getExpirationDate(): ?DrupalDateTime {
    try {
      /** @var \Drupal\datetime\Plugin\Field\FieldType\DateTimeFieldItemList $expiration_date */
      $expiration_date = $this->get('expiration_date');
      return $expiration_date->first()->date;
    }
    catch (MissingDataException $e) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setExpirationDate(?DrupalDateTime $date): EmbargoInterface {
    $this->set('expiration_date', is_null($date) ? $date : $date->format(DateTimeItemInterface::DATE_STORAGE_FORMAT));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getExemptIps(): ?IpRangeInterface {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $exempt_ips */
    $exempt_ips = $this->get('exempt_ips');
    $range = $exempt_ips->referencedEntities();
    return !empty($range) ? $range[0] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setExemptIps(?IpRangeInterface $range): EmbargoInterface {
    $this->set('exempt_ips', $range);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getExemptUsers(): array {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $exempt_users */
    $exempt_users = $this->get('exempt_users');
    return $exempt_users->referencedEntities();
  }

  /**
   * {@inheritdoc}
   */
  public function setExemptUsers(array $users): EmbargoInterface {
    $this->set('exempt_users', $users);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getExemptRoles(): array {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $exempt_roles */
    $exempt_roles = $this->get('exempt_roles');
    return $exempt_roles->referencedEntities();
  }

  /**
   * {@inheritdoc}
   */
  public function setExemptRoles(array $roles): EmbargoInterface {
    $this->set('exempt_roles', $roles);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAdditionalEmails(): array {
    return array_map(function ($email) {
      return $email['value'];
    }, $this->get('additional_emails')->getValue());
  }

  /**
   * {@inheritdoc}
   */
  public function setAdditionalEmails(array $emails): ?EmbargoInterface {
    $this->set('additional_emails', $emails);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getEmbargoedNode(): ?NodeInterface {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $field */
    $field = $this->get('embargoed_node');
    $nodes = $field->referencedEntities();
    return !empty($nodes) ? $nodes[0] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setEmbargoedNode(NodeInterface $node): EmbargoInterface {
    $this->set('embargoed_node', $node);
    return $this;
  }

  /**
   * The maximum age for which this object may be cached.
   *
   * @return int
   *   The maximum time in seconds that this object may be cached.
   */
  public function getCacheMaxAge() {
    $now = time();
    // Invalidate cache after a scheduled embargo expires.
    if ($this->getExpirationType() === static::EXPIRATION_TYPE_SCHEDULED && !$this->expiresBefore($now)) {
      return $this->getExpirationDate()->getTimestamp() - $now;
    }
    // Other properties of the embargo are not time dependent.
    return parent::getCacheMaxAge();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $tags = parent::getCacheTags();
    $tags[] = "node:{$this->getEmbargoedNode()->id()}";
    if ($this->getExemptIps()) {
      $tags = Cache::mergeTags($tags, $this->getExemptIps()->getCacheTags());
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function expiresBefore(int $date): bool {
    if ($this->getExpirationType() === static::EXPIRATION_TYPE_INDEFINITE) {
      return FALSE;
    }
    return $this->getExpirationDate()->getTimestamp() < $date;
  }

  /**
   * {@inheritdoc}
   */
  public function isUserExempt(AccountInterface $user): bool {
    $exempt_users = $this->getExemptUsers();
    $has_permission = $user->hasPermission('bypass embargo access');
    return $has_permission || in_array($user->id(), array_map(function (UserInterface $user) {
      return $user->id();
    }, $exempt_users));
  }

  /**
   * {@inheritdoc}
   */
  public function isUserRoleExempt(AccountInterface $user): bool {
    $exempt_role_ids = array_map(function (RoleInterface $role) {
      return $role->id();
    }, $this->getExemptRoles());
    return count(array_intersect($exempt_role_ids, $user->getRoles())) > 0;
  }
  /**
   * {@inheritdoc}
   */
  public function ipIsExempt(string $ip): bool {
    $exempt_ips = $this->getExemptIps();
    return $exempt_ips && $exempt_ips->withinRanges($ip);
  }

}
