# Request resolver

Symfony bundle for resolving Request Dto based on Openapi attributes
***
## Installation
Install this package using composer
```
composer require gchernikov/request-resolver-bundle
```
## Usage

### Example 1. Hydrate Request Dto from GET parameters only

Request Dto class:

```php
#[AsResolvableRequest]
class GetAllOrderRequest
{
    #[Date]
    public ?string $dateFrom = null;

    #[Date]
    #[GreaterThanOrEqual(propertyPath: 'dateFrom')]
    public ?string $dateTo = null;
}

```

Controller class:

```php
#[Route(
    path: '/order',
    name: 'order:get_all',
    methods: [Request::METHOD_GET],
)]
#[OA\Tag('Order')]
#[OA\Get(
    operationId: 'order:get_all',
    description: 'List of all user\'s orders with filtering',
    summary: 'Orders history',
    parameters: [
        new OA\Parameter(
            name: 'dateFrom',
            description: 'Period date from',
            in: 'query',
            schema: new OA\Schema(type: 'date', nullable: true),
        ),
        new OA\Parameter(
            name: 'dateTo',
            description: 'Period date to',
            in: 'query',
            schema: new OA\Schema(type: 'date', nullable: true),
        )
    ],
    responses: [
        new App\Response\Ok(type: GetAllOrderResponse::class),
    ],
)]
#[App\Response\ValidationError]
#[App\Response\UnexpectedError]
class GetAllOrderController extends AbstractController
{
    public function __invoke(GetAllOrderRequest $request): Response
    {
        // Demo for showing request Dto
        dump($request);
        
        // Place some logic to handle your request in $this->operation
        $result = ($this->operation)($request);
        
        return $this->json($result);
    }
}
```

### Example 2. Hydrate Request Dto from both GET parameters and POST data 

Request Dto class:

```php
#[AsResolvableRequest]
final class CreateOrderRequest
{
    #[NotBlank]
    #[GreaterThanOrEqual(1)]
    public int $quantity;
}

```

Controller class:

```php
#[Route(
    path: '/product/{productId}/order',
    name: 'order:create',
    methods: [Request::METHOD_POST],
)]
#[OA\Tag('Order')]
#[OA\Post(
    operationId: 'order:create',
    description: 'Create order for a certain product',
    summary: 'Creates order',
    requestBody: new RequestBody(
        required: true,
        content: new JsonContent(
            ref: new Model(type: CreateOrderRequest::class),
        ),
    ),
    parameters: [
        new OA\Parameter(
            name: 'productId',
            description: 'Id of product',
            in: 'path',
            schema: new OA\Schema(type: 'int', nullable: false),
            example: 123,
        ),
    ],
    responses: [
        new Created(type: CreateOrderResponse::class),
    ],
)]
class CreateOrderController extends AbstractController
{
    public function __invoke(CreateOrderRequest $request): Response
    {
        // Demo for showing request Dto
        dump($request);
        
        // Place some logic to handle your request in $this->operation
        $result = ($this->operation)($request);
        
        return $this->json($result);
    }
}
```
