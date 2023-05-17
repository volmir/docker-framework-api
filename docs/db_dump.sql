-- --------------------------------------------------------
-- Хост:                         127.0.0.1
-- Версия сервера:               8.0.33 - MySQL Community Server - GPL
-- Операционная система:         Linux
-- HeidiSQL Версия:              12.3.0.6589
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Дамп структуры для таблица no_framework_api.items
CREATE TABLE IF NOT EXISTS `items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` char(255) NOT NULL,
  `phone` char(15) NOT NULL,
  `key` char(25) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Дамп данных таблицы no_framework_api.items: ~6 rows (приблизительно)
INSERT INTO `items` (`id`, `name`, `phone`, `key`, `created_at`, `updated_at`) VALUES
	(4, 'John Doe', '+380507462395', '28hNMh33772msdj3jhWPMo3sk', '2023-05-17 11:01:13', '2023-05-17 11:01:13'),
	(9, 'Nil Cuttur', '+380987462126', '3hNM33M772msdj3jhWPMoDML', '2023-05-17 11:01:18', '2023-05-17 11:01:18'),
	(10, 'Jenny Wassel', '+380677462302', '923dH4Mh33772msdj3jhWPMo3', '2023-05-17 11:01:20', '2023-05-17 11:23:00'),
	(11, 'John Doe', '+380507462395', '39hNMh33772msdj3jhWPMoKRY', '2023-05-17 11:01:20', '2023-05-17 11:01:20'),
	(12, 'Mary Jastin', '+380447462199', '2wWMY3434fqa42345m32543n4', '2023-05-17 11:03:42', '2023-05-17 10:03:42'),
	(18, 'John Doe', '+380507462395', '28hNMh33772msdj3jhWPMo3sk', '2023-05-17 11:22:49', '2023-05-17 11:22:49');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
