<?php

namespace Drupal\embargo\Entity;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\embargo\IpRangeInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Defines the IP Range entity.
 *
 * @ContentEntityType(
 *   id = "embargo_ip_range",
 *   label = @Translation("IP Range"),
 *   label_collection = @Translation("IP Ranges"),
 *   label_singular = @Translation("IP Range"),
 *   label_plural = @Translation("IP Ranges"),
 *   label_count = @PluralTranslation(
 *     singular = "@count IP Range",
 *     plural = "@count IP Ranges"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\embargo\IpRangeStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\embargo\EmbargoListBuilder",
 *     "form" = {
 *       "add" = "Drupal\embargo\Form\IpRangeForm",
 *       "edit" = "Drupal\embargo\Form\IpRangeForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     },
 *   },
 *   list_cache_tags = { "node_list", "media_list", "file_list" },
 *   base_table = "embargo_ip_range",
 *   admin_permission = "administer embargo",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "canonical" = "/embargo/range/{embargo_ip_range}/edit",
 *     "add-form" = "/embargo/range/add",
 *     "edit-form" = "/embargo/range/{embargo_ip_range}/edit",
 *     "delete-form" = "/embargo/range/{embargo_ip_range}/delete",
 *     "collection" = "/admin/content/embargo/range"
 *   }
 * )
 */
class IpRange extends ContentEntityBase implements IpRangeInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('Unique label for this IP range.'))
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(TRUE)
      // It's not possible to have two ip ranges with the same label.
      ->addConstraint('UniqueField')
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
      ]);

    $fields['ranges'] = BaseFieldDefinition::create('string')
      ->setLabel(t('IP Range'))
      ->setDescription(t('IP4 or IPV6 address range in CIDR notation.'))
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(TRUE)
      ->addConstraint('ValidCidr')
      // 39 characters in IPV6 address plus 3 for the mask.
      ->setSetting('max_length', 42)
      ->setDisplayConfigurable('view', FALSE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
      ]);

    $fields['proxy'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Proxy URL'))
      ->setDescription(t("A proxy URL that can be used to gain access to this IP range.<br/>This URL will be used to generate a suggested proxy link with the embargoed resource's URL appended, so please include any required parameters."))
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setRequired(FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'uri',
      ])
      ->setDisplayOptions('view', [
        'type' => 'uri_link',
        'label' => 'hidden',
      ]);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return $this->get('label')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getRanges(): array {
    $ranges = [];
    foreach ($this->get('ranges') as $range) {
      $ranges[] = $range->value;
    }
    return $ranges;
  }

  /**
   * {@inheritdoc}
   */
  public function setRanges(array $ranges): IpRangeInterface {
    foreach ($ranges as $range) {
      if (static::isValidRange($range) === FALSE) {
        throw new \InvalidArgumentException("Invalid range '$range' has been given");
      }
    }
    $this->set('ranges', $ranges);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function withinRanges(string $ip): bool {
    $ranges = $this->getRanges();
    foreach ($ranges as $range) {
      if (IpUtils::checkIp($ip, $range)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getProxyUrl(): ?string {
    return $this->get('proxy')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setProxyUrl(string $proxy_url): IpRangeInterface {
    if (UrlHelper::isValid($proxy_url) === FALSE) {
      throw new \InvalidArgumentException("Invalid proxy url '$proxy_url' has been given");
    }
    $this->set('proxy', $proxy_url);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities) {
    // See if there's any referenced embargoes that are using this IP range and
    // prevent deletion if so.
    $entity_ids = array_map(function ($entity) {
      return $entity->id();
    }, $entities);
    $has_reference = (bool) \Drupal::service('entity_type.manager')->getStorage('embargo')->getQuery()
      ->condition('exempt_ips', $entity_ids, 'IN')
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if ($has_reference) {
      // Use the 409 (Conflict) status code to indicate that the deletion
      // could not be completed due to a conflict with the current state of
      // the target resource.
      $embargo_ids = implode(', ', $entity_ids);
      throw new EntityStorageException("The IP embargo is referenced by embargoes ({$embargo_ids}) and cannot be deleted.", 409);
    }
    parent::preDelete($storage, $entities);
  }

  /**
   * {@inheritdoc}
   */
  public static function isValidRange(string $range): bool {
    return static::isValidIP($range) || static::isValidCIDR($range);
  }

  /**
   * {@inheritdoc}
   */
  public static function isValidIp(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP) !== FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function isValidCidr(string $cidr): bool {
    $parts = explode('/', $cidr);
    if (count($parts) != 2) {
      return FALSE;
    }
    $ip = $parts[0];
    $mask = intval($parts[1]);
    if ($mask < 0) {
      return FALSE;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE) {
      return $mask <= 32;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE) {
      return $mask <= 128;
    }
    return FALSE;
  }

}
