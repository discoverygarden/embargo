<?php

namespace Drupal\embargo;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Provides an interface for defining IP Range config entities.
 */
interface IpRangeInterface extends ContentEntityInterface {

  /**
   * Gets the label used for this IP range.
   *
   * @return string
   *   The label to be used for this IP range.
   */
  public function label(): string;

  /**
   * Gets the list of IP ranges.
   *
   * Ranges can be an IPv4/IPv6 address or in CIDR notation.
   *
   * @return string[]
   *   Returns an array of strings representing ranges.
   */
  public function getRanges(): array;

  /**
   * Sets the list of IP ranges.
   *
   * Ranges can be an IPv4/IPv6 address or in CIDR notation.
   *
   * @param string[] $ranges
   *   List of range values.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   If any one of the given ranges is not in a valid format.
   */
  public function setRanges(array $ranges): IpRangeInterface;

  /**
   * Checks if the given IP address is within any of this entities ranges.
   *
   * @param string $ip
   *   Either an IPv4 or IPv6 address to check.
   *
   * @return bool
   *   TRUE if the IP address is within at least one of this entities ranges.
   */
  public function withinRanges(string $ip): bool;

  /**
   * Gets the proxy URL for this IP range.
   *
   * @return string
   *   A URL to use as a proxy for this range, to refer users to a location
   *   representative of the network blocked by this range.
   */
  public function getProxyUrl(): ?string;

  /**
   * Sets the proxy URL for this IP range.
   *
   * @param string $proxy_url
   *   A URL to use as a proxy for this range, to refer users to a location
   *   representative of the network blocked by this range.
   *
   * @return $this
   */
  public function setProxyUrl(string $proxy_url): IpRangeInterface;

  /**
   * Checks if the given value is a valid range.
   *
   * Ranges can be exact IPv4 or IPv6 values or in CIDR notation.
   *
   * @param string $range
   *   The value to check.
   *
   * @return bool
   *   TRUE if the given value is a valid range.
   */
  public static function isValidRange(string $range): bool;

  /**
   * Checks if the given value is a valid IP address.
   *
   * Supports both IPv4 and IPv6.
   *
   * @param string $ip
   *   The value to check.
   *
   * @return bool
   *   TRUE if the given value is a valid IPv4 or IPv6 address, FALSE otherwise.
   */
  public static function isValidIp(string $ip): bool;

  /**
   * Checks if the given value is in valid CIDR notation.
   *
   * Supports both IPv4 and IPv6.
   *
   * @param string $cidr
   *   The value to check.
   *
   * @return bool
   *   TRUE if the given value is valid CIDR notation, FALSE otherwise.
   */
  public static function isValidCidr(string $cidr): bool;

}
