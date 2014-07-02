<?php
/**
 * @package api
 * @subpackage filters.base
 * @abstract
 */
abstract class KalturaAssetParamsBaseFilter extends KalturaFilter
{
	private $map_between_objects = array
	(
		"systemNameEqual" => "_eq_system_name",
		"systemNameIn" => "_in_system_name",
		"isSystemDefaultEqual" => "_eq_is_system_default",
		"tagsEqual" => "_eq_tags",
	);

	private $order_by_map = array
	(
	);

	public function getMapBetweenObjects()
	{
		return array_merge(parent::getMapBetweenObjects(), $this->map_between_objects);
	}

	public function getOrderByMap()
	{
		return array_merge(parent::getOrderByMap(), $this->order_by_map);
	}

	/**
	 * @var string
	 */
	public $systemNameEqual;

	/**
	 * @var string
	 */
	public $systemNameIn;

	/**
	 * @var KalturaNullableBoolean
	 */
	public $isSystemDefaultEqual;

	/**
	 * @var string
	 */
	public $tagsEqual;
}