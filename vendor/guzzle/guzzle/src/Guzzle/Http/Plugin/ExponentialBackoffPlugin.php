<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Curl\CurlMultiInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin to automatically retry failed HTTP requests using truncated
 * exponential backoff.
 */
class ExponentialBackoffPlugin implements EventSubscriberInterface
{
    const DELAY_PARAM = 'plugins.exponential_backoff.retry_time';

    /**
     * @var array Array of response codes that must be retried
     */
    protected $failureCodes;

    /**
     * @var int Maximum number of times to retry a request
     */
    protected $maxRetries;

    /**
     * @var Collection Request state information
     */
    protected $state;

    /**
     * @var Closure
     */
    protected $delayClosure;

    /**
     * Construct a new exponential backoff plugin
     *
     * @param int $maxRetries (optional) The maximum number of time to retry a request
     * @param array $failureCodes (optional) Pass a custom list of failure codes. This
     *     can be a list of numeric codes that match the response code, or a list of
     *     reason phrases that can match the reason phrase of a request.
     * @param callable $delayFunction (optional) Method used to calculate the
     *      delay between requests.  The method must accept an integer containing
     *      the current number of retries and return an integer representing how
     *      many seconds to delay
     */
    public function __construct($maxRetries = 3, array $failureCodes = null, $delayFunction = null)
    {
        $this->setMaxRetries($maxRetries);
        $this->failureCodes = $failureCodes ?: array(500, 503);
        $this->delayClosure = $delayFunction ?: array($this, 'calculateWait');
        $this->state = new Collection();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            'request.sent' => 'onRequestSent',
            CurlMultiInterface::POLLING_REQUEST => 'onRequestPoll'
        );
    }

    /**
     * Set the maximum number of retries the plugin should use before failing
     * the request
     *
     * @param integer $maxRetries The maximum number of retries.
     *
     * @return ExponentialBackoffPlugin
     */
    public function setMaxRetries($maxRetries)
    {
        $this->maxRetries = max(0, (int) $maxRetries);

        return  $this;
    }

    /**
     * Get the maximum number of retries the plugin will attempt
     *
     * @return integer
     */
    public function getMaxRetries()
    {
        return $this->maxRetries;
    }

    /**
     * Get the HTTP response codes that should be retried using truncated
     * exponential backoff
     *
     * @return array
     */
    public function getFailureCodes()
    {
        return $this->failureCodes;
    }

    /**
     * Set the HTTP response codes that should be retried using truncated
     * exponential backoff
     *
     * @param array $codes Array of HTTP response codes
     *
     * @return ExponentialBackoffPlugin
     */
    public function setFailureCodes(array $codes)
    {
        $this->failureCodes = $codes;

        return $this;
    }

    /**
     * Determine how long to wait using truncated exponential backoff
     *
     * @param int $retries Number of retries so far
     *
     * @return int
     */
    public function calculateWait($retries)
    {
        return (int) pow(2, $retries);
    }

    /**
     * Called when a request has been sent
     *
     * @param Event $event
     */
    public function onRequestSent(Event $event)
    {
        // Called when the request has been sent and isn't finished processing
        $request = $event['request'];
        $response = $request->getResponse();
        $key = spl_object_hash($request);

        // Check if the response code or reason phrase is a match the values we expect
        if (in_array($response->getStatusCode(), $this->failureCodes) ||
            in_array($response->getReasonPhrase(), $this->failureCodes)) {
            // If this request has been retried too many times, then throw an exception
            $this->state[$key] = $this->state[$key] + 1;
            if ($this->state[$key] <= $this->maxRetries) {
                // Calculate how long to wait until the request should be retried
                $delay = (int) call_user_func($this->delayClosure, $this->state[$key]);
                // Send the request again
                $request->setState(RequestInterface::STATE_TRANSFER);
                $request->getParams()->set(self::DELAY_PARAM, time() + $delay);
            }
        }
    }

    /**
     * Called when a request is polling in the curl mutli object
     *
     * @param Event $event
     */
    public function onRequestPoll(Event $event)
    {
        $request = $event['request'];
        $delay = $request->getParams()->get(self::DELAY_PARAM);
        // If the duration of the delay has passed, retry the request using the pool
        if ($delay && time() >= $delay) {
            // Remove the request from the pool and then add it back again
            $multi = $event['curl_multi'];
            $multi->remove($request);
            $multi->add($request, true);
            $request->getParams()->remove(self::DELAY_PARAM);
        }
    }
}
