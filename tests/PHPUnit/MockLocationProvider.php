<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

class MockLocationProvider extends Piwik_UserCountry_LocationProvider
{
	private $locations = array();
	private $currentLocation = 0;
	private $ipToLocations = array();
	
	public function setLocations( $locations )
	{
		$this->locations = $locations;
	}
	
	public function getLocation( $info )
	{
		$ip = $info['ip'];
		
		if (isset($this->ipToLocations[$ip]))
		{
			$result = $this->ipToLocations[$ip];
		}
		else
		{
			$result = $this->locations[$this->currentLocation];
			$this->currentLocation = ($this->currentLocation + 1) % count($this->locations);
			
			$this->ipToLocations[$ip] = $result;
		}
		$this->completeLocationResult($result);
		return $result;
	}
	
	public function getInfo()
	{
		return array('id' => 'mock_provider', 'title' => 'mock provider', 'description' => 'mock provider');
	}
	
	public function isAvailable()
	{
		return true;
	}
	
	public function isWorking()
	{
		return true;
	}
	
	public function getSupportedLocationInfo()
	{
		return array(); // unimplemented
	}
}
