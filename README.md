# 🧩 DDD Mapper Generator для PHP

> 🚀 **Автоматическая генерация высокопроизводительных, типизированных мапперов между Domain Entity и DTO в стиле DDD.**  
> Поддержка конструкторов, коллекций, вложенных объектов, кастомных маппингов и DI-совместимости.

Этот инструмент генерирует **чистые PHP-классы мапперов** на этапе сборки — без Reflection в рантайме.  
Подходит для проектов с архитектурой: **DDD, Clean Architecture, Hexagonal, CQRS**.

---

## 📌 Оглавление

- [🚀 Быстрый старт](#-быстрый-старт)
- [🔧 Требования](#-требования)
- [🧩 Примеры использования](#-примеры-использования)
  - [1. Простой маппинг свойств](#1-простой-маппинг-свойств)
  - [2. Маппинг через конструкторы](#2-маппинг-через-конструкторы)
  - [3. Коллекции (массивы объектов)](#3-коллекции-массивы-объектов)
  - [4. Вложенные объекты](#4-вложенные-объекты)
  - [5. Кастомные имена полей (`MapTo`)](#5-кастомные-имена-полей-maptop)
  - [6. Кастомные методы маппинга](#6-кастомные-методы-маппинга)
  - [7. Несколько DTO для одной Entity](#7-несколько-dto-для-одной-entity)
- [⚙️ Конфигурация](#️-конфигурация)
  - [Автоматическое сопоставление](#автоматическое-сопоставление)
  - [Явная конфигурация (`mappers.yaml`)](#явная-конфигурация-mappersyaml)
  - [Атрибуты на DTO (`MapsFromEntity`)](#атрибуты-на-dto-mapsfromentity)
- [🖥️ CLI Команды](#️-cli-команды)
- [🧰 Makefile команды](#️-makefile-команды)
- [💡 Советы и лучшие практики](#-советы-и-лучшие-практики)
- [🤝 Вклад в проект](#-вклад-в-проект)

---

## 🚀 Быстрый старт

### 1. Установка

```bash
composer install
```

### 2. Создай Entity и DTO

```php
// src/Entity/User.php
class User
{
    private int $id;
    private string $firstName;
    private string $lastName;

    public function __construct(int $id, string $firstName, string $lastName)
    {
        $this->id = $id;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
    }

    public function getId(): int { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }
}
```

```php
// src/Dto/UserProfileDto.php
class UserProfileDto
{
    public function __construct(
        public int $id,
        public string $fullName
    ) {}
}
```

### 3. Запусти генерацию

```bash
php bin/console generate:mappers
```

### 4. Используй маппер

```php
// generated/Mapper/UserToProfileMapper.php (автоматически сгенерирован)
class UserToProfileMapper
{
    public function toDto(User $entity): UserProfileDto
    {
        return new UserProfileDto(
            id: $entity->getId(),
            fullName: $entity->getFirstName() . ' ' . $entity->getLastName()
        );
    }
}
```

> ⚠️ Но стоп! Мы не указали, как маппить `fullName` — давай это исправим 👇

---

## 🧩 Примеры использования

---

### 1. Простой маппинг свойств

Если имена совпадают — ничего дополнительно указывать не нужно.

```php
// src/Entity/Address.php
class Address
{
    public function __construct(
        public string $street,
        public string $city
    ) {}
}
```

```php
// src/Dto/AddressDto.php
class AddressDto
{
    public string $street;
    public string $city;
}
```

✅ Результат:

```php
class AddressMapper
{
    public function toDto(Address $entity): AddressDto
    {
        $dto = new AddressDto();
        $dto->street = $entity->street;
        $dto->city = $entity->city;
        return $dto;
    }
}
```

---

### 2. Маппинг через конструкторы

Если DTO или Entity используют конструкторы — маппер использует их.

```php
// src/Dto/UserReadDto.php
class UserReadDto
{
    public function __construct(
        public int $userId,
        public string $name
    ) {}
}
```

```php
// src/Entity/User.php (тот же, что выше)
```

✅ Результат:

```php
class UserReadMapper
{
    public function toDto(User $entity): UserReadDto
    {
        return new UserReadDto(
            userId: $entity->getId(),
            name: $entity->getFirstName() . ' ' . $entity->getLastName()
        );
    }
}
```

> Но `name` не совпадает с геттерами — нужно указать маппинг вручную → см. пример 5.

---

### 3. Коллекции (массивы объектов)

Используй атрибут `#[MapCollection]`.

```php
// src/Entity/User.php
use App\Attribute\MapCollection;

class User
{
    #[MapCollection(Address::class)]
    private array $addresses;

    public function __construct(int $id, string $firstName, string $lastName, array $addresses = [])
    {
        $this->id = $id;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->addresses = $addresses;
    }

    public function getAddresses(): array { return $this->addresses; }
}
```

```php
// src/Dto/UserReadDto.php
use App\Attribute\MapCollection;

class UserReadDto
{
    public function __construct(
        public int $userId,
        public string $name,
        #[MapCollection(AddressDto::class)]
        public array $addresses
    ) {}
}
```

✅ Результат:

```php
class UserReadMapper
{
    public function __construct(private AddressMapper $addressMapper) {}

    public function toDto(User $entity): UserReadDto
    {
        return new UserReadDto(
            userId: $entity->getId(),
            name: $entity->getFirstName() . ' ' . $entity->getLastName(),
            addresses: array_map(
                fn(Address $item) => $this->addressMapper->toDto($item),
                $entity->getAddresses()
            )
        );
    }
}
```

---

### 4. Вложенные объекты

Просто используй тип в конструкторе или свойстве — маппер сам определит зависимость.

```php
// src/Entity/Order.php
class Order
{
    public function __construct(
        public int $id,
        public User $user
    ) {}
}
```

```php
// src/Dto/OrderDto.php
class OrderDto
{
    public function __construct(
        public int $id,
        public UserReadDto $user
    ) {}
}
```

✅ Результат:

```php
class OrderMapper
{
    public function __construct(private UserReadMapper $userReadMapper) {}

    public function toDto(Order $entity): OrderDto
    {
        return new OrderDto(
            id: $entity->id,
            user: $this->userReadMapper->toDto($entity->user)
        );
    }
}
```

---

### 5. Кастомные имена полей (`MapTo`)

Когда имена в Entity и DTO не совпадают.

```php
// src/Entity/User.php
use App\Attribute\MapTo;

class User
{
    #[MapTo('userId')]
    private int $id;

    #[MapTo('fullName')]
    private string $firstName;

    private string $lastName;

    public function __construct(int $id, string $firstName, string $lastName)
    {
        $this->id = $id;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
    }

    public function getId(): int { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }
}
```

```php
// src/Dto/UserProfileDto.php
class UserProfileDto
{
    public int $userId;
    public string $fullName;
}
```

✅ Результат:

```php
class UserToProfileMapper
{
    public function toDto(User $entity): UserProfileDto
    {
        $dto = new UserProfileDto();
        $dto->userId = $entity->getId();
        $dto->fullName = $entity->getFirstName(); // ← НЕПРАВИЛЬНО — не хватает lastName!
        return $dto;
    }
}
```

> ⚠️ Так не пойдёт — нужно кастомное преобразование → см. пример 6.

---

### 6. Кастомные методы маппинга

Добавь статический метод в DTO и укажи его в `MapTo`.

```php
// src/Dto/UserProfileDto.php
class UserProfileDto
{
    public int $userId;
    public string $fullName;

    public static function buildFullName(string $firstName, string $lastName): string
    {
        return "$firstName $lastName";
    }
}
```

```php
// src/Entity/User.php
use App\Attribute\MapTo;

class User
{
    #[MapTo('userId')]
    private int $id;

    #[MapTo('fullName', mapperMethod: 'UserProfileDto::buildFullName')]
    private string $firstName;

    private string $lastName;

    public function __construct(int $id, string $firstName, string $lastName)
    {
        $this->id = $id;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
    }

    public function getId(): int { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }
}
```

✅ Результат:

```php
class UserToProfileMapper
{
    public function toDto(User $entity): UserProfileDto
    {
        $dto = new UserProfileDto();
        $dto->userId = $entity->getId();
        $dto->fullName = UserProfileDto::buildFullName(
            $entity->getFirstName(),
            $entity->getLastName()
        );
        return $dto;
    }
}
```

---

### 7. Несколько DTO для одной Entity

#### Вариант A: Автоматически (по именам)

```php
// src/Dto/UserReadDto.php
class UserReadDto
{
    public int $id;
    public string $name;
}
```

```php
// src/Dto/UserProfileDto.php
class UserProfileDto
{
    public int $id;
    public string $displayName;
}
```

```php
// src/Dto/UserShortDto.php
class UserShortDto
{
    public int $id;
}
```

→ Генерируются:  
- `UserToReadMapper.php`  
- `UserToProfileMapper.php`  
- `UserToShortMapper.php`

#### Вариант B: Через конфиг `config/mappers.yaml`

```yaml
mappers:
    - entity: App\Entity\User
      dto: App\Dto\UserReadDto
      mapper_name: UserReadMapper

    - entity: App\Entity\User
      dto: App\Dto\UserProfileDto
      mapper_name: UserProfileMapper
```

#### Вариант C: Через атрибут на DTO

```php
// src/Dto/UserAdminDto.php
use App\Attribute\MapsFromEntity;

#[MapsFromEntity(entityClass: \App\Entity\User::class, mapperName: 'UserAdminMapper')]
class UserAdminDto
{
    public int $id;
    public string $email;
    public bool $isAdmin;
}
```

---

## ⚙️ Конфигурация

### Автоматическое сопоставление

- Ищет все `*.php` в `src/Entity/`.
- Для каждого `User.php` ищет `User*Dto.php` в `src/Dto/`.
- Генерирует `UserToXxxMapper.php`.

### Явная конфигурация (`config/mappers.yaml`)

```yaml
mappers:
    - entity: App\Entity\Order
      dto: App\Dto\OrderSummaryDto
      mapper_name: OrderSummaryMapper

    - entity: App\Entity\Product
      dto: App\Dto\ProductCardDto
      # mapper_name не указан — будет ProductToCardMapper
```

### Атрибуты на DTO (`MapsFromEntity`)

```php
// src/Dto/ApiUserDto.php
use App\Attribute\MapsFromEntity;

#[MapsFromEntity(\App\Entity\User::class)]
class ApiUserDto
{
    public int $id;
    public string $login;
}
```

```php
// src/Dto/UserExportDto.php
use App\Attribute\MapsFromEntity;

#[MapsFromEntity(\App\Entity\User::class, 'UserExportMapper')]
class UserExportDto
{
    public string $exportId;
    public string $fullName;
}
```

---

## 🖥️ CLI Команды

```bash
# Основная генерация
php bin/console generate:mappers

# С опциями
php bin/console generate:mappers \
    --entity-path=src/Domain/Model \
    --dto-path=src/Application/DataTransfer \
    --output-path=src/Infrastructure/Mapper \
    --namespace=App\Infrastructure\Mapper \
    --config=config/custom_mappers.yaml \
    --clear

# Помощь
php bin/console generate:mappers --help
```

---

## 🧰 Makefile команды

```bash
# Установка зависимостей
make install

# Генерация мапперов с очисткой директории
make mappers

# Очистка сгенерированных мапперов
make clean
```

Содержимое `Makefile`:

```makefile
.PHONY: mappers clean install

mappers:
	php bin/console generate:mappers --clear

clean:
	rm -rf generated/Mapper/*

install:
	composer install
```

---

## 💡 Советы и лучшие практики

- ✅ **Не используй мапперы в домене** — только на границах (Application/Infrastructure).
- ✅ **Делай DTO immutable** — используй конструкторы.
- ✅ **Не коммить `generated/` в репозиторий** — генерируй в CI/CD или pre-commit хуке.
- ✅ **Покрывай тестами кастомные маппинги** — особенно с `mapperMethod`.
- ✅ **Используй осмысленные имена мапперов**: `UserToApiMapper`, `OrderToPdfDtoMapper`.
- ✅ **Добавляй типы и PHPDoc** — это улучшает поддержку в IDE и статических анализаторах.

---

## 🤝 Вклад в проект

PR приветствуются! Особенно:

- Поддержка Value Objects
- Генерация PHPUnit-тестов для мапперов
- Laravel Artisan-версия команды
- Watch-режим (перегенерация при изменении файлов)
- Интеграция с PHPStan / Psalm
- Docker-образ для изолированного запуска

---

> 🧑‍💻 Сгенерировано с ❤️ для DDD-проектов на PHP  
> 🏷️ Версия: 1.0  
> 📄 Лицензия: MIT
