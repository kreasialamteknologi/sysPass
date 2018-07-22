<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      https://syspass.org
 * @copyright 2012-2018, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Services\Track;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\DataModel\TrackData;
use SP\Http\Request;
use SP\Repositories\NoSuchItemException;
use SP\Repositories\Track\TrackRepository;
use SP\Repositories\Track\TrackRequest;
use SP\Services\Service;

/**
 * Class TrackService
 *
 * @package SP\Services
 */
class TrackService extends Service
{
    /**
     * Tiempo para contador de intentos
     */
    const TIME_TRACKING = 600;
    const TIME_TRACKING_MAX_ATTEMPTS = 10;
    const TIME_SLEEP = 0.5;

    /**
     * @var TrackRepository
     */
    protected $trackRepository;
    /**
     * @var Request
     */
    protected $request;

    /**
     * @param string $source
     *
     * @return TrackRequest
     * @throws \SP\Core\Exceptions\InvalidArgumentException
     */
    public function getTrackRequest($source)
    {
        $trackRequest = new TrackRequest();
        $trackRequest->time = time() - self::TIME_TRACKING;
        $trackRequest->setTrackIp($this->request->getClientAddress());
        $trackRequest->source = $source;

        return $trackRequest;
    }

    /**
     * @param $id int|array
     *
     * @throws \SP\Core\Exceptions\QueryException
     * @throws \SP\Core\Exceptions\ConstraintException
     * @throws NoSuchItemException
     */
    public function delete($id)
    {
        if ($this->trackRepository->delete($id) === 0) {
            throw new NoSuchItemException(__u('Track no encontrado'));
        }
    }

    /**
     * @param $id int
     *
     * @return TrackData
     * @throws \SP\Core\Exceptions\ConstraintException
     * @throws \SP\Core\Exceptions\QueryException
     * @throws NoSuchItemException
     */
    public function getById($id)
    {
        $result = $this->trackRepository->getById($id);

        if ($result->getNumRows() === 0) {
            throw new NoSuchItemException(__u('Track no encontrado'));
        }

        return $result->getData();
    }

    /**
     * @return TrackData[]
     * @throws \SP\Core\Exceptions\ConstraintException
     * @throws \SP\Core\Exceptions\QueryException
     */
    public function getAll()
    {
        return $this->trackRepository->getAll()->getDataAsArray();
    }

    /**
     * Comprobar los intentos de login
     *
     * @param TrackRequest $trackRequest
     *
     * @return bool True if delay is performed, false otherwise
     * @throws \Exception
     */
    public function checkTracking(TrackRequest $trackRequest)
    {
        try {
            $attempts = $this->getTracksForClientFromTime($trackRequest);

            if ($attempts >= self::TIME_TRACKING_MAX_ATTEMPTS) {
                $this->eventDispatcher->notifyEvent('track.delay',
                    new Event($this, EventMessage::factory()
                        ->addDescription(sprintf(__('Intentos excedidos (%d/%d)'), $attempts, self::TIME_TRACKING_MAX_ATTEMPTS))
                        ->addDetail(__u('Segundos'), self::TIME_SLEEP * $attempts))
                );

                debugLog('Tracking delay: ' . self::TIME_SLEEP * $attempts . 's');

                sleep(self::TIME_SLEEP * $attempts);

                return true;
            }
        } catch (\Exception $e) {
            processException($e);

            throw $e;
        }

        return false;
    }

    /**
     * Devuelve los tracks de un cliente desde un tiempo y origen determinados
     *
     * @param TrackRequest $trackRequest
     *
     * @return int
     * @throws \SP\Core\Exceptions\ConstraintException
     * @throws \SP\Core\Exceptions\QueryException
     */
    public function getTracksForClientFromTime(TrackRequest $trackRequest)
    {
        return $this->trackRepository->getTracksForClientFromTime($trackRequest)->getNumRows();
    }

    /**
     * @param TrackRequest $trackRequest
     *
     * @return int
     * @throws \SP\Core\Exceptions\ConstraintException
     * @throws \SP\Core\Exceptions\QueryException
     */
    public function add(TrackRequest $trackRequest)
    {
        $result = $this->trackRepository->add($trackRequest);

        $this->eventDispatcher->notifyEvent('track.add',
            new Event($this, EventMessage::factory()
                ->addDescription($this->request->getClientAddress(true)))
        );

        return $result;
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function initialize()
    {
        $this->trackRepository = $this->dic->get(TrackRepository::class);
        $this->request = $this->dic->get(Request::class);
    }
}