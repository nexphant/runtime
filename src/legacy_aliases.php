<?php
class_alias(\Nexph\Core\Context\RuntimeContext::class, 'Nexph\Runtime\Context\RuntimeContext');
class_alias(\Nexph\Core\Context\ContextStore::class, 'Nexph\Runtime\Context\ContextStore');
class_alias(\Nexph\Core\Ownership\OwnerType::class, 'Nexph\Runtime\Ownership\OwnerType');
class_alias(\Nexph\Core\Ownership\OwnerId::class, 'Nexph\Runtime\Ownership\OwnerId');
class_alias(\Nexph\Core\Ownership\RuntimeOwner::class, 'Nexph\Runtime\Ownership\RuntimeOwner');
class_alias(\Nexph\Core\Ownership\OwnerRegistry::class, 'Nexph\Runtime\Ownership\OwnerRegistry');
class_alias(\Nexph\Core\Resource\RuntimeResource::class, 'Nexph\Runtime\Resource\RuntimeResource');
class_alias(\Nexph\Core\Resource\ResourceRegistry::class, 'Nexph\Runtime\Resource\ResourceRegistry');
class_alias(\Nexph\Core\Cancellation\CancellationToken::class, 'Nexph\Runtime\Cancellation\CancellationToken');
class_alias(\Nexph\Core\Cancellation\CancellationSource::class, 'Nexph\Runtime\Cancellation\CancellationSource');
class_alias(\Nexph\Core\Cancellation\CancelledException::class, 'Nexph\Runtime\Cancellation\CancelledException');
class_alias(\Nexph\Core\Cancellation\Deadline::class, 'Nexph\Runtime\Cancellation\Deadline');
class_alias(\Nexph\Core\Cancellation\DeadlineExceededException::class, 'Nexph\Runtime\Cancellation\DeadlineExceededException');
class_alias(\Nexph\Core\Drain\DrainController::class, 'Nexph\Runtime\Drain\DrainController');
class_alias(\Nexph\Queue\QueueFactory::class, 'Nexph\Runtime\Queue\QueueFactory');
