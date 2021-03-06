<?php
namespace verbb\events\elements;

use verbb\events\Events;
use verbb\events\elements\db\TicketQuery;
use verbb\events\events\CustomizeEventSnapshotDataEvent;
use verbb\events\events\CustomizeEventSnapshotFieldsEvent;
use verbb\events\events\CustomizeTicketSnapshotDataEvent;
use verbb\events\events\CustomizeTicketSnapshotFieldsEvent;
use verbb\events\helpers\TicketHelper;
use verbb\events\records\TicketRecord;
use verbb\events\records\PurchasedTicketRecord;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\actions\Delete;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\validators\DateTimeValidator;

use craft\commerce\Plugin as Commerce;
use craft\commerce\base\Purchasable;
use craft\commerce\elements\Order;
use craft\commerce\helpers\Currency;
use craft\commerce\models\LineItem;
use craft\commerce\models\ProductType;
use craft\commerce\models\Sale;

use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\db\Expression;

class Ticket extends Purchasable
{
    // Constants
    // =========================================================================
  
    const EVENT_BEFORE_CAPTURE_TICKET_SNAPSHOT = 'beforeCaptureTicketSnapshot';
    const EVENT_AFTER_CAPTURE_TICKET_SNAPSHOT = 'afterCaptureTicketSnapshot';
    const EVENT_BEFORE_CAPTURE_EVENT_SNAPSHOT = 'beforeCaptureEventSnapshot';
    const EVENT_AFTER_CAPTURE_EVENT_SNAPSHOT = 'afterCaptureEventSnapshot';


    // Static
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('events', 'Ticket');
    }

    public static function refHandle()
    {
        return 'ticket';
    }

    public static function hasContent(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function find(): ElementQueryInterface
    {
        return new TicketQuery(static::class);
    }

    protected static function defineSources(string $context = null): array
    {
        $sources = [[
            'key' => '*',
            'label' => Craft::t('events', 'All events'),
            'defaultSort' => ['postDate', 'desc'],
        ]];

        $sources[] = ['heading' => Craft::t('events', 'Events')];

        $events = Event::find()->all();

        foreach ($events as $event) {
            $key = 'event:' . $event->id;

            $sources[] = [
                'key' => $key,
                'label' => $event->title,
                'criteria' => [
                    'eventId' => $event->id,
                ]
            ];
        }

        return $sources;
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['sku', 'price'];
    }


    // Element index methods
    // -------------------------------------------------------------------------

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'event' => ['label' => Craft::t('events', 'Event')],
            'sku' => ['label' => Craft::t('commerce', 'SKU')],
            'price' => ['label' => Craft::t('commerce', 'Price')],
            'quantity' => ['label' => Craft::t('events', 'Quantity')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        $attributes = [];

        if ($source === '*') {
            $attributes[] = 'event';
        }

        $attributes[] = 'title';
        $attributes[] = 'sku';
        $attributes[] = 'price';

        return $attributes;
    }


    // Properties
    // =========================================================================

    public $eventId;
    public $typeId;
    public $sku;
    public $quantity;
    public $price;
    public $availableFrom;
    public $availableTo;
    public $sortOrder;
    public $deletedWithEvent = false;

    private $_event;
    private $_ticketType;


    // Public Methods
    // =========================================================================

    public function __toString(): string
    {
        $event = $this->getEvent();

        if ($event) {
            return "{$this->event}: {$this->getName()}";
        } else {
            return parent::__toString();
        }
    }

    public function getName(): string
    {
        return $this->getType()->title ?? '';
    }

    public function rules()
    {
        $rules = parent::rules();

        $rules[] = [['sku'], 'string'];
        $rules[] = [['sku', 'price', 'typeId'], 'required'];
        $rules[] = [['price'], 'number'];

        return $rules;
    }

    public function datetimeAttributes(): array
    {
        $attributes = parent::datetimeAttributes();
        $attributes[] = 'availableFrom';
        $attributes[] = 'availableTo';

        return $attributes;
    }

    public function extraAttributes(): array
    {
        $names = parent::extraAttributes();
        $names[] = 'event';
        return $names;
    }

    public function getFieldLayout()
    {
        return $this->getType()->getFieldLayout();
    }

    public function getEvent()
    {
        if ($this->_event !== null) {
            return $this->_event;
        }

        if ($this->eventId === null) {
            throw new InvalidConfigException('Ticket is missing its event');
        }

        $event = Event::find()
            ->id($this->eventId)
            ->siteId($this->siteId)
            ->anyStatus()
            ->trashed(null)
            ->one();

        if ($event === null) {
            throw new InvalidConfigException('Invalid event ID: ' . $this->eventId);
        }

        return $this->_event = $event;
    }

    public function setEvent(Event $event)
    {
        if ($event->siteId) {
            $this->siteId = $event->siteId;
        }

        if ($event->id) {
            $this->eventId = $event->id;
        }

        $this->_event = $event;
    }

    public function getType(): TicketType
    {
        if ($this->typeId === null) {
            throw new InvalidConfigException('Ticket is missing its ticket type ID');
        }

        $ticketType = Events::getInstance()->getTicketTypes()->getTicketTypeById($this->typeId);

        if (null === $ticketType) {
            throw new InvalidConfigException('Invalid ticket type ID: ' . $this->typeId);
        }

        return $ticketType;
    }

    public function attributeLabels(): array
    {
        $labels = parent::attributeLabels();

        return array_merge($labels, ['sku' => 'SKU']);
    }

    public function getIsEditable(): bool
    {
        $event = $this->getEvent();

        if ($event) {
            return $event->getIsEditable();
        }

        return false;
    }

    public function getCpEditUrl(): string
    {
        return $this->getEvent() ? $this->getEvent()->getCpEditUrl() : null;
    }

    public static function eagerLoadingMap(array $sourceElements, string $handle): array
    {
        if ($handle == 'event') {
            // Get the source element IDs
            $sourceElementIds = [];

            foreach ($sourceElements as $sourceElement) {
                $sourceElementIds[] = $sourceElement->id;
            }

            $map = (new Query())
                ->select('id as source, eventId as target')
                ->from('events_tickets')
                ->where(['in', 'id', $sourceElementIds])
                ->all();

            return [
                'elementType' => Event::class,
                'map' => $map,
            ];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    public function setEagerLoadedElements(string $handle, array $elements)
    {
        if ($handle == 'event') {
            $event = $elements[0] ?? null;
            $this->setEvent($event);
        } else {
            parent::setEagerLoadedElements($handle, $elements);
        }
    }

    public function getIsAvailable(): bool
    {
        if ($this->getStatus() !== Element::STATUS_ENABLED) {
            return false;
        }

        $currentTime = DateTimeHelper::currentTimeStamp();

        if ($this->availableFrom) {
            $availableFrom = $this->availableFrom->getTimestamp();

            if ($availableFrom > $currentTime) {
                return false;
            }
        }

        if ($this->availableTo) {
            $availableTo = $this->availableTo->getTimestamp();

            if ($availableTo < $currentTime) {
                return false;
            }
        }

        // Check if there are any tickets left
        $purchasedTickets = Events::$plugin->getPurchasedTickets()->getAllPurchasedTickets([
            'ticketId' => $this->id,
        ]);

        if (($this->quantity - count($purchasedTickets)) === 0) {
            return false;
        }

        return true;
    }

    public function getStatus()
    {
        $status = parent::getStatus();

        $eventStatus = $this->getEvent()->getStatus();

        if ($eventStatus != Event::STATUS_LIVE) {
            return Element::STATUS_DISABLED;
        }

        return $status;
    }

    public function populateLineItem(LineItem $lineItem)
    {
        $errors = [];

        if ($lineItem->purchasable === $this) {
            $purchasedTickets = Events::$plugin->getPurchasedTickets()->getAllPurchasedTickets(['ticketId' => $lineItem->purchasable->id]);
            $availableTickets = $lineItem->purchasable->quantity - count($purchasedTickets);

            if ($lineItem->qty > $availableTickets) {
                $lineItem->qty = $availableTickets;
                $errors[] = 'You reached the maximum ticket quantity for ' . $lineItem->purchasable->getDescription();
            }
        }

        if ($errors) {
            $cart = Commerce::getInstance()->getCarts()->getCart();
            $cart->addErrors($errors);

            Craft::$app->getSession()->setError(implode(',', $errors));
        }
    }

    public function afterOrderComplete(Order $order, LineItem $lineItem)
    {
        // Reduce quantity
        Craft::$app->getDb()->createCommand()->update('{{%events_tickets}}',
            ['quantity' => new Expression('quantity - :qty', [':qty' => $lineItem->qty])],
            ['id' => $this->id])->execute();

        // Update the quantity
        $this->quantity = (new Query())
            ->select(['quantity'])
            ->from('{{%events_tickets}}')
            ->where('id = :ticketId', [':ticketId' => $this->id])
            ->scalar();

        Craft::$app->getTemplateCaches()->deleteCachesByElementId($this->id);

        // Generate purchased tickets
        for ($i = 0; $i < $lineItem->qty; $i++) {
            $record = new PurchasedTicketRecord();
            $record->eventId = $this->eventId;
            $record->ticketId = $this->id;
            $record->orderId = $order->id;
            $record->lineItemId = $lineItem->id;
            $record->ticketSku = TicketHelper::generateTicketSKU();

            $record->save(false);
        }
    }

    public function getPurchasedTickets(LineItem $lineItem)
    {
        return Events::$plugin->getPurchasedTickets()->getAllPurchasedTickets([
            'orderId' => $lineItem->order->id,
            'lineItemId' => $lineItem->id
        ]);
    }

    public function getPurchasedTicketsForLineItem(LineItem $lineItem)
    {
        Craft::$app->getDeprecator()->log('Ticket::getPurchasedTicketsForLineItem(item)', 'item.purchasable.getPurchasedTicketsForLineItem(item) has been deprecated. Use item.purchasable.getPurchasedTickets(item) instead');

        return $this->getPurchasedTickets($lineItem);
    }


    // Purchasable
    // =========================================================================

    public function getPurchasableId(): int
    {
        return $this->id;
    }

    public function getSnapshot(): array
    {
        $data = [];
        $data['onSale'] = $this->getOnSale();

        $data['cpEditUrl'] = $this->getEvent() ? $this->getEvent()->getCpEditUrl() : [];

        // Event Attributes
        $data['event'] = $this->getEvent() ? $this->getEvent()->getSnapshot() : [];

        // Default Event custom field handles
        $eventFields = [];
        $eventFieldsEvent = new CustomizeEventSnapshotFieldsEvent([
            'event' => $this->getEvent(),
            'fields' => $eventFields
        ]);

        // Allow plugins to modify Event fields to be fetched
        if ($this->hasEventHandlers(self::EVENT_BEFORE_CAPTURE_EVENT_SNAPSHOT)) {
            $this->trigger(self::EVENT_BEFORE_CAPTURE_EVENT_SNAPSHOT, $eventFieldsEvent);
        }

        // Capture specified Event field data
        $eventFieldData = $this->getEvent() ? $this->getEvent()->getSerializedFieldValues($eventFieldsEvent->fields) : [];
        $eventDataEvent = new CustomizeEventSnapshotDataEvent([
            'event' => $this->getEvent(),
            'fieldData' => $eventFieldData
        ]);

        // Allow plugins to modify captured Event data
        if ($this->hasEventHandlers(self::EVENT_AFTER_CAPTURE_EVENT_SNAPSHOT)) {
            $this->trigger(self::EVENT_AFTER_CAPTURE_EVENT_SNAPSHOT, $eventDataEvent);
        }

        $data['eventFields'] = $eventDataEvent->fieldData;

        // Default Ticket custom field handles
        $ticketFields = [];
        $ticketFieldsEvent = new CustomizeTicketSnapshotFieldsEvent([
            'ticket' => $this,
            'fields' => $ticketFields
        ]);

        // Allow plugins to modify fields to be fetched
        if ($this->hasEventHandlers(self::EVENT_BEFORE_CAPTURE_TICKET_SNAPSHOT)) {
            $this->trigger(self::EVENT_BEFORE_CAPTURE_TICKET_SNAPSHOT, $ticketFieldsEvent);
        }

        // Capture specified Ticket field data
        $ticketFieldData = $this->getSerializedFieldValues($ticketFieldsEvent->fields);
        $ticketDataEvent = new CustomizeTicketSnapshotDataEvent([
            'ticket' => $this,
            'fieldData' => $ticketFieldData
        ]);

        // Allow plugins to modify captured Ticket data
        if ($this->hasEventHandlers(self::EVENT_AFTER_CAPTURE_TICKET_SNAPSHOT)) {
            $this->trigger(self::EVENT_AFTER_CAPTURE_TICKET_SNAPSHOT, $ticketDataEvent);
        }

        $data['fields'] = $ticketDataEvent->fieldData;

        return array_merge($this->getAttributes(), $data);
    }

    public function getOnSale(): bool
    {
        return null === $this->salePrice ? false : (Currency::round($this->salePrice) != Currency::round($this->price));
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getDescription(): string
    {
        return (string)$this;
    }

    public function getTaxCategoryId(): int
    {
        return $this->getType()->taxCategoryId;
    }

    public function getShippingCategoryId(): int
    {
        return $this->getType()->shippingCategoryId;
    }

    public function hasFreeShipping(): bool
    {
        return true;
    }

    public function getIsPromotable(): bool
    {
        return true;
    }

    public function getIsShippable(): bool
    {
        return false;
    }

    public function getIsTaxable(): bool
    {
        return true;
    }


    // Events
    // -------------------------------------------------------------------------

    public function afterSave(bool $isNew)
    {
        if (!$isNew) {
            $record = TicketRecord::findOne($this->id);

            if (!$record) {
                throw new Exception('Invalid ticket ID: ' . $this->id);
            }
        } else {
            $record = new TicketRecord();
            $record->id = $this->id;
        }

        $record->eventId = $this->eventId;
        $record->typeId = $this->typeId;
        $record->sku = $this->sku;
        $record->quantity = $this->quantity;
        $record->price = $this->price;
        $record->availableFrom = $this->availableFrom;
        $record->availableTo = $this->availableTo;
        $record->sortOrder = $this->sortOrder;

        $record->save(false);

        return parent::afterSave($isNew);
    }

    public function beforeDelete(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        Craft::$app->getDb()->createCommand()
            ->update('{{%events_tickets}}', [
                'deletedWithEvent' => $this->deletedWithEvent,
            ], ['id' => $this->id], [], false)
            ->execute();

        return true;
    }

    public function beforeRestore(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        // Check to see if any other purchasable has the same SKU and update this one before restore
        $found = (new Query())->select(['[[p.sku]]', '[[e.id]]'])
            ->from('{{%commerce_purchasables}} p')
            ->leftJoin(Table::ELEMENTS . ' e', '[[p.id]]=[[e.id]]')
            ->where(['[[e.dateDeleted]]' => null, '[[p.sku]]' => $this->getSku()])
            ->andWhere(['not', ['[[e.id]]' => $this->getId()]])
            ->count();

        if ($found) {
            // Set new SKU in memory
            $this->sku = $this->getSku() . '-1';

            // Update ticket table with new SKU
            Craft::$app->getDb()->createCommand()->update('{{%events_tickets}}',
                ['sku' => $this->sku],
                ['id' => $this->getId()]
            )->execute();

            // Update purchasable table with new SKU
            Craft::$app->getDb()->createCommand()->update('{{%commerce_purchasables}}',
                ['sku' => $this->sku],
                ['id' => $this->getId()]
            )->execute();
        }

        return true;
    }


    // Protected methods
    // =========================================================================

    protected function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'event': {
                return $this->event->title;
            }

            case 'price': {
                $code = Commerce::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso();

                return Craft::$app->getLocale()->getFormatter()->asCurrency($this->$attribute, strtoupper($code));
            }

            default: {
                return parent::tableAttributeHtml($attribute);
            }
        }
    }

}
