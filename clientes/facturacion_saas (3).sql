-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost
-- Tiempo de generación: 30-03-2026 a las 19:06:22
-- Versión del servidor: 10.4.28-MariaDB
-- Versión de PHP: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `facturacion_saas`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `bitacora_facturas`
--

CREATE TABLE `bitacora_facturas` (
  `id` int(11) NOT NULL,
  `factura_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `accion` varchar(130) NOT NULL,
  `motivo` text DEFAULT NULL,
  `detalles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`detalles`)),
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip` varchar(45) DEFAULT NULL,
  `autorizador_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `bitacora_facturas`
--

INSERT INTO `bitacora_facturas` (`id`, `factura_id`, `usuario_id`, `accion`, `motivo`, `detalles`, `fecha`, `ip`, `autorizador_id`) VALUES
(1, 29, 4, 'eliminada', 'Erro de dedo', NULL, '2025-06-23 05:37:20', '::1', 4),
(2, 30, 4, 'eliminada', 'asdfasf', NULL, '2025-06-23 05:38:47', '::1', 4),
(3, 28, 4, 'editada', 'Edición manual', NULL, '2025-06-23 06:12:07', '::1', NULL),
(4, 28, 4, 'editada', 'Edición manual', NULL, '2025-06-23 06:14:56', '::1', NULL),
(5, 28, 4, 'editada', 'Edición manual', NULL, '2025-06-23 06:15:02', '::1', NULL),
(6, 28, 4, 'editada', 'Edición manual', NULL, '2025-06-23 06:17:19', '::1', NULL),
(7, 28, 4, 'editada', 'Edición manual', NULL, '2025-06-23 06:17:27', '::1', NULL),
(8, 28, 5, 'anulada', 'ASDFASDF', NULL, '2025-06-23 06:28:25', NULL, 4),
(9, 28, 5, 'emitida', 'ASDFASF', NULL, '2025-06-23 06:28:41', NULL, 4),
(10, 28, 5, 'editada', 'Edición manual', NULL, '2025-06-23 06:28:53', '::1', NULL),
(11, 28, 5, 'editada', 'Edición manual', NULL, '2025-06-23 06:29:02', '::1', NULL),
(12, 28, 5, 'editada', 'asdad', NULL, '2025-06-23 06:39:54', '::1', NULL),
(13, 28, 5, 'editada', 'asd', NULL, '2025-06-23 06:40:04', '::1', NULL),
(14, 28, 5, 'editada', 'asdasd', NULL, '2025-06-23 06:40:26', '::1', NULL),
(15, 28, 5, 'editada', 'asdf', NULL, '2025-06-23 06:40:36', '::1', NULL),
(16, 28, 5, 'editada', 'sadfa', NULL, '2025-06-23 06:51:35', '::1', 4),
(17, 28, 5, 'editada', 'sadfa', NULL, '2025-06-23 06:51:49', '::1', 4),
(18, 31, 5, 'eliminada', 'Error de Precio', NULL, '2025-06-23 16:14:09', '::1', 4),
(19, 32, 5, 'editada', 'Actualización de precio', NULL, '2025-06-23 17:51:33', '::1', 4),
(20, 32, 5, 'editada', 'asd', NULL, '2025-06-23 17:52:09', '::1', 4),
(21, 32, 5, 'editada', 'asda', NULL, '2025-06-23 21:57:31', '::1', 4),
(22, 33, 5, 'eliminada', 'asd', NULL, '2025-06-24 04:08:42', '::1', 4),
(23, 32, 5, 'editada', 'asd', NULL, '2025-06-24 05:05:36', '::1', 4),
(24, 32, 5, 'editada', 'asd', NULL, '2025-06-24 05:22:55', '::1', 4),
(25, 32, 5, 'editada', 'sadf', NULL, '2025-06-24 05:27:08', '::1', 4),
(26, 34, 5, 'editada', 'asd', NULL, '2025-06-24 05:29:24', '::1', 4),
(27, 34, 5, 'editada', 'asd', NULL, '2025-06-24 05:31:06', '::1', 4),
(28, 34, 5, 'eliminada', 'adasd', NULL, '2025-06-24 05:31:25', '::1', 4),
(29, 35, 5, 'editada', 'asd', NULL, '2025-06-24 05:33:10', '::1', 4),
(30, 35, 5, 'eliminada', 'asdad', NULL, '2025-06-24 05:36:16', '::1', 4),
(31, 36, 5, 'editada', 'asda', NULL, '2025-06-24 05:36:56', '::1', 4),
(32, 36, 5, 'editada', 'asd', NULL, '2025-06-24 05:39:37', '::1', 4),
(33, 36, 5, 'editada', 'asd', NULL, '2025-06-24 05:44:19', '::1', 4),
(34, 36, 4, 'eliminada', 'asd', NULL, '2025-06-24 07:14:22', '::1', 4),
(35, 37, 5, 'eliminada', 'asd', NULL, '2025-06-24 07:54:10', '::1', 4),
(36, 38, 5, 'eliminada', 'asd', NULL, '2025-06-24 07:59:53', '::1', 4),
(37, 43, 5, 'editada', 'asd', NULL, '2025-06-24 08:11:45', '::1', 4),
(38, 43, 5, 'editada', 'asd', NULL, '2025-06-24 08:12:01', '::1', 4),
(39, 43, 5, 'editada', 'asda', NULL, '2025-06-24 08:12:21', '::1', 4),
(40, 43, 5, 'eliminada', 'asdasd', NULL, '2025-06-24 08:13:39', '::1', 4),
(41, 44, 5, 'editada', 'asd', NULL, '2025-06-24 08:16:10', '::1', 4),
(42, 45, 5, 'eliminada', 'asdasd', NULL, '2025-06-24 08:20:41', '::1', 4),
(43, 46, 5, 'eliminada', 'safd', NULL, '2025-06-24 08:24:12', '::1', 4),
(44, 47, 5, 'eliminada', 'ASDASD', NULL, '2025-06-24 08:31:39', '::1', 4),
(45, 48, 7, 'eliminada', 'asda', NULL, '2025-07-03 20:04:07', '::1', 7),
(46, 49, 7, 'eliminada', 'asd', NULL, '2025-07-03 20:07:38', '::1', 7),
(47, 50, 7, 'eliminada', 'asd', NULL, '2025-07-03 20:19:57', '::1', 7),
(48, 51, 7, 'eliminada', 'asd', NULL, '2025-07-03 20:20:22', '::1', 7),
(49, 32, 7, 'editada', 'asd', NULL, '2025-07-03 21:15:44', '::1', 7),
(50, 67, 7, 'eliminada', 'asd', NULL, '2025-07-07 18:06:44', '::1', 7),
(51, 68, 7, 'eliminada', 'asd', NULL, '2025-07-07 18:20:11', '::1', 7),
(52, 52, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:00:13', '::1', 7),
(53, 66, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:00:28', '::1', 7),
(54, 53, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:00:51', '::1', 7),
(55, 54, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:01:08', '::1', 7),
(56, 55, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:01:28', '::1', 7),
(57, 56, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:01:45', '::1', 7),
(58, 57, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:02:02', '::1', 7),
(59, 58, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:02:16', '::1', 7),
(60, 59, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:03:33', '::1', 7),
(61, 60, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:03:44', '::1', 7),
(62, 61, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:03:52', '::1', 7),
(63, 62, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:04:01', '::1', 7),
(64, 63, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:04:09', '::1', 7),
(65, 64, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:04:19', '::1', 7),
(66, 65, 7, 'editada', 'Fecha', NULL, '2025-07-09 21:04:28', '::1', 7),
(67, 14, 7, 'editada', 'Items', NULL, '2025-07-09 21:46:53', '::1', 7),
(68, 73, 7, 'editada', 'AGREGAR MAS SERVICIOS', NULL, '2025-07-25 08:30:33', '::1', 7),
(69, 73, 7, 'editada', 'ASD', NULL, '2025-07-25 08:37:27', '::1', 7),
(70, 73, 7, 'editada', 'asd', NULL, '2025-07-25 08:48:38', '::1', 7),
(71, 73, 7, 'editada', 'ASD', NULL, '2025-07-25 08:50:05', '::1', 7),
(72, 73, 7, 'editada', 'ASD', NULL, '2025-07-25 08:50:17', '::1', 7),
(73, 73, 7, 'editada', 'ASD', NULL, '2025-07-25 08:50:27', '::1', 7),
(74, 28, 7, 'editada', 'asd', NULL, '2025-07-25 09:56:38', '::1', 7),
(75, 74, 7, 'editada', 'asd', NULL, '2025-07-25 11:44:50', '::1', 7),
(76, 74, 7, 'editada', 'asd', NULL, '2025-07-25 11:52:47', '::1', 7),
(77, 74, 7, 'editada', 'asd', NULL, '2025-07-25 11:53:23', '::1', 7),
(78, 74, 7, 'editada', 'sdfgv', NULL, '2025-07-25 11:59:02', '::1', 7),
(79, 77, 7, 'eliminada', 'asd', NULL, '2025-07-28 20:21:37', '::1', 7),
(80, 78, 7, 'eliminada', 'as', NULL, '2025-07-28 20:25:19', '::1', 7),
(81, 76, 7, 'eliminada', 'asd', NULL, '2025-07-28 20:25:23', '::1', 7),
(82, 74, 7, 'eliminada', 'asd', NULL, '2025-07-28 20:25:28', '::1', 7),
(83, 73, 7, 'eliminada', 'asd', NULL, '2025-07-28 20:25:32', '::1', 7),
(84, 83, 7, 'eliminada', 'asd', NULL, '2025-07-28 22:39:13', '::1', 7),
(85, 84, 7, 'eliminada', 'SAD', NULL, '2025-07-28 22:45:56', '::1', 7),
(86, 85, 7, 'eliminada', 'AS', NULL, '2025-07-28 22:48:45', '::1', 7),
(87, 86, 7, 'eliminada', 'ASD', NULL, '2025-07-28 22:48:49', '::1', 7),
(88, 87, 7, 'editada', 'ASD', NULL, '2025-07-28 22:50:24', '::1', 7),
(89, 88, 7, 'eliminada', 'ASDE', NULL, '2025-07-28 22:58:37', '::1', 7),
(90, 87, 7, 'eliminada', 'ASD', NULL, '2025-07-28 22:58:40', '::1', 7),
(91, 93, 7, 'anulada', 'Datos malos', NULL, '2025-07-28 23:12:58', NULL, 7),
(92, 94, 7, 'anulada', 'Error de precio', NULL, '2025-07-28 23:14:18', NULL, 7),
(93, 99, 7, 'anulada', 'Error de precio', NULL, '2025-07-28 23:34:04', NULL, 7),
(94, 102, 7, 'anulada', 'Error de precio', NULL, '2025-07-28 23:41:20', NULL, 7),
(95, 104, 7, 'anulada', 'ERROR DE CORRELATIVO', NULL, '2025-07-28 23:43:03', NULL, 7),
(96, 105, 7, 'anulada', 'POR EXONERACIÓN', NULL, '2025-07-28 23:45:42', NULL, 7),
(97, 108, 7, 'anulada', 'ERROR EN PRECIO', NULL, '2025-07-28 23:58:14', NULL, 7),
(98, 112, 7, 'editada', 'DESCRIPCION', NULL, '2025-07-29 00:07:28', '::1', 7),
(99, 114, 7, 'anulada', 'PRODUCTOS ERROR', NULL, '2025-07-29 00:09:20', NULL, 7),
(100, 121, 7, 'anulada', 'ERROR DEDO', NULL, '2025-07-29 00:32:26', NULL, 7),
(101, 119, 7, 'anulada', 'ERROR PRECIO', NULL, '2025-07-29 00:35:51', NULL, 7),
(102, 101, 7, 'editada', 'ACTUALIZACION EXONERACION', NULL, '2025-07-29 00:54:32', '::1', 7),
(103, 69, 7, 'editada', 'ASD', NULL, '2025-08-01 17:55:52', '::1', 7),
(104, 69, 7, 'editada', 'GHJ', NULL, '2025-08-01 17:57:19', '::1', 7),
(105, 125, 7, 'eliminada', 'SWDFS', NULL, '2025-08-01 19:56:25', '::1', 7),
(106, 79, 7, 'editada', 'AS', NULL, '2025-08-01 19:57:34', '::1', 7),
(107, 82, 7, 'editada', 'ACTUALIZACIÓN DE PRODUCTOS', NULL, '2025-08-01 22:59:17', '::1', 7),
(108, 81, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-08-04 21:27:27', '::1', 7),
(109, 75, 7, 'cambio_estado', 'szxc', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-08-04 21:40:38', '::1', 7),
(110, 82, 7, 'anulada', 'Prueba', NULL, '2025-08-06 23:42:16', NULL, 7),
(111, 82, 7, 'emitida', 'as', NULL, '2025-08-06 23:42:28', NULL, 7),
(112, 126, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0}}', '2025-08-07 23:55:21', '::1', 7),
(113, 126, 7, 'eliminada', 'asd', NULL, '2025-08-07 23:55:50', '::1', 7),
(114, 127, 7, 'eliminada', 'as', NULL, '2025-08-08 00:16:00', '::1', 7),
(115, 82, 7, 'editada', 'ASD', NULL, '2025-08-09 22:04:48', '::1', 7),
(116, 82, 7, 'editada', 'AS', NULL, '2025-08-09 22:06:13', '::1', 7),
(117, 128, 7, 'cambio_estado', 'GHJ', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-09 22:06:39', '::1', 7),
(118, 81, 7, 'editada', 'asd', NULL, '2025-08-12 22:30:48', '::1', 7),
(119, 82, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-08-14 01:28:00', '::1', 7),
(120, 81, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-14 01:28:13', '::1', 7),
(121, 129, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-08-14 17:21:01', '::1', 7),
(122, 28, 7, 'cambio_estado', 'gj', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"1\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-14 19:34:53', '::1', 7),
(123, 130, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-14 19:55:43', '::1', 7),
(124, 131, 7, 'cambio_estado', 'dfh', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-08-18 17:29:44', '::1', 4),
(125, 82, 7, 'cambio_estado', 'XCV', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-20 23:21:50', '::1', 4),
(126, 80, 7, 'cambio_estado', 'SD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-20 23:22:07', '::1', 4),
(127, 79, 7, 'cambio_estado', 'SDG', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-20 23:22:16', '::1', 4),
(128, 132, 7, 'eliminada', 'xcv', NULL, '2025-08-25 16:33:58', '::1', 4),
(129, 133, 7, 'cambio_estado', 'asdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-08-25 16:42:31', '::1', 4),
(130, 133, 7, 'cambio_estado', 'sd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-26 21:00:50', '::1', 7),
(131, 131, 7, 'cambio_estado', 'fgh', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-26 21:05:40', '::1', 7),
(132, 129, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-26 21:05:48', '::1', 7),
(133, 75, 7, 'cambio_estado', 'sad', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-08-27 05:57:42', '::1', 7),
(134, 134, 7, 'editada', 'asd', NULL, '2025-08-27 20:40:01', '::1', 7),
(135, 134, 7, 'editada', 'XCV', NULL, '2025-08-29 19:33:14', '::1', 7),
(136, 134, 7, 'editada', 'ASD', NULL, '2025-09-02 04:45:50', '::1', 7),
(137, 134, 7, 'eliminada', 'as', NULL, '2025-09-02 15:36:17', '::1', 7),
(138, 75, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-02 19:56:55', '::1', 7),
(139, 79, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-02 19:57:04', '::1', 7),
(140, 80, 7, 'cambio_estado', 'zas', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-02 19:57:14', '::1', 7),
(141, 81, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-02 19:57:20', '::1', 7),
(142, 82, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-02 19:57:33', '::1', 7),
(143, 128, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-02 19:57:38', '::1', 7),
(144, 129, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-02 19:57:43', '::1', 7),
(145, 130, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-02 19:57:48', '::1', 7),
(146, 131, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-02 19:57:53', '::1', 7),
(147, 133, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-02 19:57:59', '::1', 7),
(148, 135, 7, 'editada', 'ASD', NULL, '2025-09-05 06:54:21', '::1', 7),
(149, 137, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-09-05 17:28:58', '::1', 7),
(150, 136, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-09-05 17:29:07', '::1', 7),
(151, 135, 7, 'cambio_estado', 'SD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-09-12 04:14:56', '::1', 7),
(152, 139, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-12 04:15:06', '::1', 7),
(153, 138, 7, 'cambio_estado', 'SAD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-09-12 04:15:14', '::1', 7),
(154, 137, 7, 'cambio_estado', 'E', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-12 04:15:33', '::1', 7),
(155, 136, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-12 04:15:39', '::1', 7),
(156, 135, 7, 'editada', 'ASD', NULL, '2025-09-12 04:16:55', '::1', 7),
(157, 135, 7, 'editada', 'asd', NULL, '2025-09-15 18:35:35', '::1', 7),
(158, 135, 7, 'editada', 'ASASD', NULL, '2025-09-15 18:36:13', '::1', 7),
(159, 135, 7, 'editada', 'ASD', NULL, '2025-09-15 18:37:24', '::1', 7),
(160, 138, 7, 'editada', 'ASD', NULL, '2025-09-16 19:25:08', '::1', 7),
(161, 138, 7, 'editada', 'ASD', NULL, '2025-09-17 02:22:25', '::1', 7),
(162, 140, 7, 'cambio_estado', 'CV', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-09-18 00:06:26', '::1', 7),
(163, 141, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-09-30 17:08:34', '::1', 7),
(164, 138, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-30 17:10:07', '::1', 7),
(165, 135, 7, 'cambio_estado', 'q', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-30 17:16:37', '::1', 7),
(166, 140, 7, 'cambio_estado', 'wqd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-09-30 17:16:45', '::1', 7),
(167, 142, 7, 'editada', 'SA', NULL, '2025-10-02 20:05:11', '::1', 7),
(168, 142, 7, 'editada', 'sad', NULL, '2025-10-03 00:00:03', '::1', 7),
(169, 143, 7, 'cambio_estado', 'AS', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-10-09 20:34:05', '::1', 7),
(170, 135, 7, 'cambio_estado', 'SDF', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-10-09 20:34:30', '::1', 7),
(171, 136, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-10-09 20:34:35', '::1', 7),
(172, 137, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-10-09 20:34:40', '::1', 7),
(173, 138, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-10-09 20:34:44', '::1', 7),
(174, 139, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-10-09 20:34:49', '::1', 7),
(175, 140, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-10-09 20:34:53', '::1', 7),
(176, 142, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-10-13 21:09:40', '::1', 7),
(177, 144, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-10-13 21:28:11', '::1', 7),
(178, 143, 7, 'cambio_estado', 'a', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-10-13 21:28:24', '::1', 7),
(179, 145, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-10-13 21:44:27', '::1', 7),
(180, 142, 7, 'editada', 'ASD', NULL, '2025-10-16 16:58:41', '::1', 7),
(181, 147, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-10-18 17:25:33', '::1', 7),
(182, 146, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-10-18 17:25:40', '::1', 7),
(183, 148, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-10-18 17:25:46', '::1', 7),
(184, 149, 7, 'editada', 'ASD', NULL, '2025-10-28 20:11:12', '::1', 7),
(185, 144, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-10-29 09:56:27', '::1', 7),
(186, 142, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-10-29 09:56:40', '::1', 7),
(187, 150, 7, 'editada', 'asd', NULL, '2025-10-29 09:57:13', '::1', 7),
(188, 149, 7, 'cambio_estado', 'as', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-10-29 09:57:42', '::1', 7),
(189, 145, 7, 'editada', 'dc', NULL, '2025-10-29 18:31:27', '::1', 7),
(190, 149, 7, 'editada', 'dfg', NULL, '2025-10-29 18:31:37', '::1', 7),
(191, 150, 7, 'editada', 'SDAF', NULL, '2025-11-03 19:15:22', '::1', 7),
(192, 150, 7, 'editada', 'ASD', NULL, '2025-11-04 16:34:20', '::1', 7),
(193, 150, 7, 'editada', 'AS', NULL, '2025-11-10 15:35:56', '::1', 7),
(194, 150, 7, 'editada', 'ASD', NULL, '2025-11-10 15:38:33', '::1', 7),
(195, 150, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-11-10 15:47:00', '::1', 7),
(196, 150, 7, 'editada', 'as', NULL, '2025-11-10 15:48:59', '::1', 7),
(197, 152, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-11-10 15:54:48', '::1', 7),
(198, 145, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-10 15:55:28', '::1', 7),
(199, 146, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-10 15:55:35', '::1', 7),
(200, 147, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-10 15:55:40', '::1', 7),
(201, 148, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-10 15:55:48', '::1', 7),
(202, 149, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-10 15:55:54', '::1', 7),
(203, 153, 7, 'editada', 'asd', NULL, '2025-11-12 17:42:11', '::1', 7),
(204, 151, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-11-12 17:43:17', '::1', 7),
(205, 152, 7, 'cambio_estado', 'f', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-12 20:42:21', '::1', 7),
(206, 153, 7, 'cambio_estado', 'fch', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-12 20:42:29', '::1', 7),
(207, 141, 7, 'cambio_estado', 'sdfg', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-18 15:33:35', '::1', 7),
(208, 153, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-11-18 15:33:56', '::1', 7),
(209, 142, 7, 'cambio_estado', 'hjk', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-18 15:43:47', '::1', 7),
(210, 143, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-18 15:43:52', '::1', 7),
(211, 144, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-18 15:44:00', '::1', 7),
(212, 145, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-18 15:44:15', '::1', 7),
(213, 146, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-18 15:44:21', '::1', 7),
(214, 147, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-18 15:44:26', '::1', 7),
(215, 148, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-11-18 15:44:31', '::1', 7),
(216, 154, 7, 'editada', 'ASD', NULL, '2025-11-18 15:45:45', '::1', 7),
(217, 154, 7, 'cambio_estado', 'AS', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"borrador\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0}}', '2025-11-18 15:45:54', '::1', 7),
(218, 154, 7, 'editada', 'ASDASD', NULL, '2025-11-20 17:08:58', '::1', 7),
(219, 154, 7, 'editada', 'ASD', NULL, '2025-11-20 20:52:20', '::1', 7),
(220, 154, 7, 'eliminada', 'ASD', NULL, '2025-11-26 00:21:03', '::1', 7),
(221, 155, 7, 'eliminada', 'ASDASD', NULL, '2025-11-26 00:21:12', '::1', 7),
(222, 157, 7, 'cambio_estado', 'dsa', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-02 15:43:00', '::1', 7),
(223, 156, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-02 15:43:08', '::1', 7),
(224, 158, 7, 'cambio_estado', 'DFG', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-12-10 21:35:59', '::1', 7),
(225, 149, 7, 'cambio_estado', 'wd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-22 00:26:18', '::1', 7),
(226, 150, 7, 'cambio_estado', 'asa', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-22 00:26:26', '::1', 7),
(227, 151, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-22 00:26:35', '::1', 7),
(228, 152, 7, 'cambio_estado', 'sc', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-22 00:26:43', '::1', 7),
(229, 153, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-22 00:26:50', '::1', 7),
(230, 156, 7, 'cambio_estado', 'das', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-22 00:26:57', '::1', 7),
(231, 157, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-22 00:27:06', '::1', 7),
(232, 160, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-22 00:27:32', '::1', 7),
(233, 159, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2025-12-22 00:27:50', '::1', 7),
(234, 162, 7, 'editada', 'g', NULL, '2025-12-22 16:26:52', '::1', 7),
(235, 162, 7, 'editada', 'asd', NULL, '2025-12-22 16:29:30', '::1', 7),
(236, 162, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-12-22 16:31:32', '::1', 7),
(237, 161, 7, 'cambio_estado', 'asda', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-12-22 22:30:06', '::1', 7),
(238, 164, 7, 'editada', 'ASD', NULL, '2025-12-29 17:13:38', '::1', 7),
(239, 163, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-12-29 17:19:32', '::1', 7),
(240, 164, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2025-12-29 17:19:43', '::1', 7),
(241, 164, 7, 'cambio_estado', 'asda', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-07 15:12:30', '::1', 7),
(242, 163, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-07 15:12:35', '::1', 7),
(243, 158, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-07 15:12:41', '::1', 7),
(244, 165, 7, 'editada', 'ASDA', NULL, '2026-01-07 15:23:46', '::1', 7),
(245, 165, 7, 'editada', 'ASD', NULL, '2026-01-07 15:24:08', '::1', 7),
(246, 166, 7, 'editada', 'sdfg', NULL, '2026-01-09 22:23:12', '::1', 7),
(247, 161, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-11 04:45:34', '::1', 7),
(248, 161, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-11 04:45:52', '::1', 7),
(249, 158, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-11 04:46:14', '::1', 7),
(250, 159, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-11 04:46:19', '::1', 7),
(251, 160, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-11 04:46:26', '::1', 7),
(252, 162, 7, 'cambio_estado', 'ASDA', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":0,\"enviada_receptor\":1}}', '2026-01-11 04:46:43', '::1', 7),
(253, 167, 7, 'eliminada', 'ASD', NULL, '2026-01-11 04:49:54', '::1', 7),
(254, 163, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-11 04:56:57', '::1', 7),
(255, 168, 7, 'cambio_estado', 'f', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-01-12 16:32:51', '::1', 7),
(256, 170, 7, 'cambio_estado', 'AS', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-01-12 16:41:37', '::1', 7),
(257, 169, 7, 'eliminada', 'asd', NULL, '2026-01-12 16:42:30', '::1', 7),
(258, 170, 7, 'eliminada', 'asd', NULL, '2026-01-12 16:42:33', '::1', 7),
(259, 171, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-01-12 16:43:00', '::1', 7),
(260, 165, 7, 'cambio_estado', 'ASDAS', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-01-12 16:43:59', '::1', 7),
(261, 171, 7, 'editada', 'ASD', NULL, '2026-01-12 17:12:53', '::1', 7),
(262, 166, 7, 'editada', 'ASD', NULL, '2026-01-12 20:20:59', '::1', 7),
(263, 168, 7, 'cambio_estado', 'swde', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-15 17:59:25', '::1', 7),
(264, 166, 7, 'editada', 'sdf', NULL, '2026-01-19 17:18:24', '::1', 7),
(265, 171, 7, 'cambio_estado', 'dfh', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-01-19 17:21:04', '::1', 7),
(266, 166, 7, 'editada', 'ASD', NULL, '2026-01-20 17:22:25', '::1', 7),
(267, 166, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-01-21 20:58:16', '::1', 7),
(268, 173, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-01-22 15:13:07', '::1', 7),
(269, 174, 7, 'eliminada', 'asd', NULL, '2026-01-28 06:24:12', '190.242.27.159', 7),
(270, 172, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"anulada\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0}}', '2026-01-30 23:41:38', '190.242.27.159', 7),
(271, 175, 7, 'editada', 'asd', NULL, '2026-02-02 17:45:41', '76.134.64.218', 7),
(272, 175, 7, 'editada', 'ACTUALIZACION', NULL, '2026-02-02 18:23:54', '76.134.64.218', 7),
(273, 175, 7, 'editada', 'PRUEBAS', NULL, '2026-02-02 18:32:55', '76.134.64.218', 7),
(274, 175, 7, 'editada', 'DOS SERVICIOS MAS', NULL, '2026-02-02 19:34:12', '76.134.64.218', 7),
(275, 175, 7, 'editada', 'LISTO', NULL, '2026-02-02 20:17:39', '76.134.64.218', 7),
(276, 162, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-02-02 20:32:12', '76.134.64.218', 7),
(277, 165, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-02-02 20:32:31', '76.134.64.218', 7),
(278, 166, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-02-02 20:32:44', '76.134.64.218', 7),
(279, 173, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-02-02 20:33:13', '76.134.64.218', 7),
(280, 168, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-02-02 20:34:48', '76.134.64.218', 7),
(281, 171, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-02-02 20:35:03', '76.134.64.218', 7),
(282, 164, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-02-02 20:35:47', '76.134.64.218', 7),
(283, 176, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-02-09 19:01:40', '190.242.27.159', 7),
(284, 177, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-02-09 19:04:58', '190.242.27.159', 7),
(285, 175, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-02-09 19:19:37', '190.242.27.159', 7),
(286, 175, 7, 'editada', 'ASD', NULL, '2026-02-09 19:22:01', '190.242.27.159', 7),
(287, 175, 7, 'editada', 'ASD', NULL, '2026-02-09 19:23:34', '190.242.27.159', 7),
(288, 175, 7, 'editada', 'ASD', NULL, '2026-02-09 19:28:06', '190.242.27.159', 7),
(289, 175, 7, 'editada', 'asd', NULL, '2026-02-09 19:28:47', '190.242.27.159', 7),
(290, 178, 7, 'cambio_estado', 'asda', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-02-09 21:42:32', '190.242.27.159', 7),
(291, 178, 7, 'editada', 'Cambio', NULL, '2026-02-10 00:03:36', '181.115.63.166', 7),
(292, 178, 7, 'editada', 'Jaja', NULL, '2026-02-10 00:06:43', '181.115.63.214', 7),
(293, 177, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-02-10 22:44:20', '190.242.27.159', 7),
(294, 179, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-02-16 01:17:12', '190.242.27.159', 7),
(295, 175, 7, 'editada', 'asd', NULL, '2026-02-18 19:13:09', '190.242.27.159', 7),
(296, 180, 7, 'editada', 'asd', NULL, '2026-02-20 17:40:23', '190.242.27.159', 7),
(297, 180, 7, 'cambio_estado', 'sdfs', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-02-21 17:00:58', '190.242.27.159', 7),
(298, 181, 7, 'editada', 'ASD', NULL, '2026-02-22 15:49:38', '::1', 7),
(299, 179, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-02-23 15:36:34', '::1', 7),
(300, 179, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"1\",\"enviada_receptor\":\"1\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-02-23 15:36:48', '::1', 7),
(301, 181, 7, 'eliminada', 'SAA', NULL, '2026-02-24 21:51:51', '::1', 7),
(302, 184, 7, 'eliminada', 'asd', NULL, '2026-02-24 21:57:59', '::1', 7),
(303, 183, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-02-24 22:05:43', '::1', 7),
(304, 182, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":\"0\",\"pagada\":\"0\",\"enviada_receptor\":\"0\"},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-02-24 22:05:51', '::1', 7),
(305, 185, 7, 'editada', 'ASD', NULL, '2026-03-11 19:35:08', '::1', 7),
(306, 183, 7, 'cambio_estado', 'dfg', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-12 15:13:52', '::1', 7),
(307, 182, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-12 15:14:00', '::1', 7),
(308, 179, 7, 'cambio_estado', 'asdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-12 15:14:11', '::1', 7),
(309, 178, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-12 15:14:22', '::1', 7);
INSERT INTO `bitacora_facturas` (`id`, `factura_id`, `usuario_id`, `accion`, `motivo`, `detalles`, `fecha`, `ip`, `autorizador_id`) VALUES
(310, 177, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-12 15:14:29', '::1', 7),
(311, 176, 7, 'cambio_estado', 'asdasd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":0,\"enviada_receptor\":1}}', '2026-03-12 15:14:42', '::1', 7),
(312, 180, 7, 'cambio_estado', 'asdas', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-12 15:14:48', '::1', 7),
(313, 176, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-12 15:15:07', '::1', 7),
(314, 175, 7, 'cambio_estado', 'sadfsadf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":1,\"pagada\":0,\"enviada_receptor\":1}}', '2026-03-12 15:15:20', '::1', 7),
(315, 186, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-12 15:16:30', '::1', 7),
(316, 186, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-03-12 15:16:41', '::1', 7),
(317, 185, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-12 15:16:48', '::1', 7),
(318, 188, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-15 04:14:58', '::1', 7),
(319, 192, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-03-16 03:40:28', '::1', 7),
(320, 193, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-03-16 03:40:36', '::1', 7),
(321, 191, 7, 'cambio_estado', 'ASDAS', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-03-16 03:40:44', '::1', 7),
(322, 189, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-03-16 03:40:53', '::1', 7),
(323, 187, 7, 'anulada', 'SDFS', NULL, '2026-03-16 03:41:32', NULL, 7),
(324, 193, 7, 'editada', 'asd', NULL, '2026-03-16 04:20:29', '::1', 7),
(325, 190, 7, 'cambio_estado', 'ASD', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-03-17 17:22:45', '::1', 7),
(326, 190, 7, 'editada', 'ASD', NULL, '2026-03-17 17:24:03', '::1', 7),
(327, 190, 7, 'editada', 'ASF', NULL, '2026-03-19 16:48:00', '::1', 7),
(328, 190, 7, 'editada', 'AS', NULL, '2026-03-19 16:48:47', '::1', 7),
(329, 7, 7, 'editada', 'ASD', NULL, '2026-03-23 20:00:46', '::1', 7),
(330, 194, 7, 'cambio_estado', 'asfd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":0},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1}}', '2026-03-26 05:54:53', '::1', 7),
(331, 193, 7, 'cambio_estado', 'sdf', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-26 21:13:08', '::1', 7),
(332, 192, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-26 21:13:16', '::1', 7),
(333, 191, 7, 'cambio_estado', 'asd', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-26 21:13:21', '::1', 7),
(334, 171, 7, 'editada', 'ASD', NULL, '2026-03-27 22:57:19', '::1', 7),
(335, 189, 7, 'cambio_estado', 'sa', '{\"previo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":0,\"enviada_receptor\":1},\"nuevo\":{\"estado\":\"emitida\",\"estado_declarada\":0,\"pagada\":1,\"enviada_receptor\":1}}', '2026-03-28 00:44:20', '::1', 7),
(336, 195, 7, 'editada', 'ASFD', NULL, '2026-03-30 16:12:43', '::1', 7);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cai_rangos`
--

CREATE TABLE `cai_rangos` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `establecimiento_id` int(11) DEFAULT NULL,
  `punto_emision_id` int(11) DEFAULT NULL,
  `cai` varchar(45) DEFAULT NULL,
  `rango_inicio` int(11) DEFAULT NULL,
  `rango_fin` int(11) DEFAULT NULL,
  `correlativo_actual` int(11) DEFAULT NULL,
  `fecha_recepcion` date DEFAULT NULL,
  `fecha_limite` date DEFAULT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp(),
  `rango_cai_inicio` varchar(400) NOT NULL,
  `rango_cai_fin` varchar(400) NOT NULL,
  `ultimo_correlativo` varchar(1000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `cai_rangos`
--

INSERT INTO `cai_rangos` (`id`, `cliente_id`, `establecimiento_id`, `punto_emision_id`, `cai`, `rango_inicio`, `rango_fin`, `correlativo_actual`, `fecha_recepcion`, `fecha_limite`, `fecha_creacion`, `rango_cai_inicio`, `rango_cai_fin`, `ultimo_correlativo`) VALUES
(1, 2, 1, 1, '2D0708-EE616A-A54EE0-63BE03-090930-0B', 1, 100, 79, '2025-01-31', '2026-01-31', '2025-06-21 15:08:52', '000-002-01-00000001', '000-002-01-00000100', '000-002-01-00000079'),
(4, 1, 3, 3, 'AB12CD-3456EF-7890GH-1234IJ-5678KL-MN', 1, 500, 1, '2025-06-23', '2026-06-23', '2025-06-24 06:40:01', '000-001-01-00000001', '000-001-01-00000500', '000-001-01-00000001'),
(5, 2, 1, 1, '35A8CE-845016-CF499E05808A-3CC2A3-FA', 151, 200, 35, '2024-02-12', '2025-02-12', '2025-07-28 14:50:31', '000-001-01-00000151', '000-001-01-00000200', '000-001-01-00000185'),
(6, 2, 1, 1, '4665EC-65B88F-A351E0-63BE03-0909C4-B9', 101, 200, 19, '2025-12-20', '2026-12-20', '2025-12-20 10:51:40', '000-002-01-00000101', '000-002-01-00000200', '000-002-01-00000119');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categorias_gastos`
--

CREATE TABLE `categorias_gastos` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#6c757d',
  `icono` varchar(60) NOT NULL DEFAULT 'fa-tag',
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `categorias_gastos`
--

INSERT INTO `categorias_gastos` (`id`, `cliente_id`, `nombre`, `color`, `icono`, `activa`, `creado_en`) VALUES
(1, 1, 'Nómina / Sueldos', '#0d6efd', 'fa-users', 1, '2026-02-23 09:41:18'),
(2, 1, 'Alquiler / Oficina', '#6f42c1', 'fa-building', 1, '2026-02-23 09:41:18'),
(3, 1, 'Servicios Públicos', '#0dcaf0', 'fa-bolt', 1, '2026-02-23 09:41:18'),
(4, 1, 'Marketing / Publicidad', '#fd7e14', 'fa-bullhorn', 1, '2026-02-23 09:41:18'),
(5, 1, 'Software / Licencias', '#20c997', 'fa-laptop-code', 1, '2026-02-23 09:41:18'),
(6, 1, 'Transporte', '#ffc107', 'fa-car', 1, '2026-02-23 09:41:18'),
(7, 1, 'Impuestos / SAR', '#dc3545', 'fa-file-invoice', 1, '2026-02-23 09:41:18'),
(8, 1, 'Bancario / Comisiones', '#495057', 'fa-university', 1, '2026-02-23 09:41:18'),
(9, 1, 'Otros', '#6c757d', 'fa-ellipsis-h', 1, '2026-02-23 09:41:18'),
(10, 2, 'Licencias Digitales', '#6f42c1', 'fa-shopping-cart', 1, '2026-02-23 09:47:50'),
(11, 2, 'Combustible', '#fd7e14', 'fa-car', 1, '2026-02-23 09:48:00'),
(12, 2, 'Equipo de Computo', '#20c997', 'fa-laptop-code', 1, '2026-02-23 09:48:23'),
(13, 2, 'Viaticos', '#0dcaf0', 'fa-car', 1, '2026-02-23 09:48:36'),
(14, 2, 'Alimentación', '#ffc107', 'fa-users', 1, '2026-02-23 09:48:53'),
(15, 2, 'Comunicaciones', '#198754', 'fa-globe', 1, '2026-02-23 09:49:45'),
(16, 2, 'Nómina / Sueldos', '#0d6efd', 'fa-users', 1, '2026-02-23 09:54:52'),
(17, 2, 'Gastos Operativos TC', '#fd7e14', 'fa-credit-card', 1, '2026-03-26 18:29:56');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clientes_factura`
--

CREATE TABLE `clientes_factura` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `rtn` varchar(25) DEFAULT NULL,
  `direccion` text DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `clientes_factura`
--

INSERT INTO `clientes_factura` (`id`, `cliente_id`, `nombre`, `rtn`, `direccion`, `telefono`, `email`) VALUES
(1, 2, 'BMT HONDURAS', '08019022354050', 'TORRE MORAZÁN, TEGUCIGALPA, FM, HONDURAS', '+504 9788-3778', 'gerencia@bmticket.com'),
(2, 2, 'DAVID VALERIANO PINTO', '03181963003492', 'SIGUATEPEQUE, COMAYAGUA, HONDURAS', '+504 9737-1006', NULL),
(3, 2, 'ESPIRIA TOUR S. DE R.L.', '08019024098304', 'Blv. Morazan edif Torre Morazan nivel C4 local 30455', NULL, NULL),
(4, 2, 'VIDRIERIA VAREL S. DE R.L', '03189006032822', 'Bo.Las Américas, 8 cuadras al este de Maxi Despensa, Siguatepeque, Honduras.', '+504 2773-3767', 'comercializacion@vidrieriavarel.com'),
(5, 2, 'ESTACIÓN DE SERVICIOS COLONIAL S. DE R.L. DE C.V.', '03019016813958', 'SIGUATEPEQUE, COMAYAGUA, HONDURAS', NULL, NULL),
(6, 2, 'DIDEPROB', '03189011402070', 'Barrio San Miguel, esquina opuesta a Promasa', NULL, NULL),
(7, 2, 'LUZ Y FUERZA DE SAN LORENZO S.A. DE C.V. (LUFUSSA)', '08019002267065', 'Edificio Corporativo Los Proceres, TGU, Honduras', '', ''),
(8, 2, 'Fundación Para El Desarrollo Empresarial Rural - FUNDER', '08019998393841', 'Edifico de la Cámara de Comercio e Industrias de Comayagua, Salida hacia la libertada, Comayagua, Honduras', NULL, NULL),
(17, 2, 'SIG-URBAN', '03189010278137', 'Barrio el Centro, Edificio Baires Palomo Siguatepeque Comayagua', '2773-5376', 'info@sigurban.com'),
(18, 2, 'CLUB DEPORTIVO GENESIS DE COMAYAGUA F.C.', '08019021255857', 'LA PAZ, LA PAZ', NULL, NULL),
(19, 2, 'PREMIER MOTORS S.A. DE C.V.', '0501-9017-942385', 'San Pedro Sula, Cortés, HN', NULL, NULL),
(20, 2, 'CENTRO DE ADIESTRAMIENTO EN ENFERMERÍA S.A. DE C.V.', '08019006045316', '2DA AVE. ENTRE 8 Y 9 CALLE, Comayagüela', '2220-6001', 'info@care.edu.hn'),
(21, 2, 'ADOLFO OCTAVIO LÓPEZ URQUÍA', '1217192000340', 'SIGUATEPEQUE, COMAYAGUA, HN', '', ''),
(22, 2, 'CARE INTERNACIONAL HONDURAS', '08019023492466', 'OFICINA PRINCIPAL, 6TO PISO, MALL EL DORADO, TEGUCIGALPA, HN', '', ''),
(23, 2, 'NULO', '0000000000000', 'NULO', 'NULO', 'NULO'),
(24, 2, 'POLICLINA MUNGUIA', '05031986002478', 'BARRIO SAN MIGUEL, BULEVAR FRANCISCO MORAZÁN', '27730050', ''),
(25, 2, 'INVERSIONES VISTA HERMOSA S.A. DE C.V.', '03189011413542', 'SIGUATEPEQUE, COMAYAGUA', '', ''),
(26, 2, 'COMPAÑÍA DISTRIBUIDORA S.A. DE C.V.', '08019003239610', 'Barrio El Rincón, 1/2 cuadra al norte de Municipalidad Col. 21 de Octubre Tegucigalpa, HN, Centro América', '+50498030881', 'alejandro.ochoa@lufussa.com'),
(27, 2, 'Proyecto Aldea Global', '08019002266976', '1 Calle N.O, Bº Santa Martha, 1 cuadra atrás clÍnica Santa María, Siguatepeque', '2773-2027', 'info@paghonduras.org'),
(28, 2, 'LÁCTEOS DE HONDURAS S.A. DE C.V. (LACTHOSA)', '05019003256756', 'Tegucigalpa, M.D.C., Honduras', '+504 2202-4060, +504 2566-0055', 'alejandro.ochoa@luffussa.com'),
(29, 2, 'SEINCO S. de R.L', '03019018025620', 'Siguatepeque, Comayagua', '+504 8840-5401', 'escotobelkis74@gmail.com'),
(30, 2, 'FUNDACIÓN SUIZA DE COOPERACIÓN PARA EL DESARROLLO TÉCNICO SWISSCONTACT', '08019002268335', 'Lomas del Guijarro, calzada Llama del Bosque, #602, Tegucigalpa, Honduras', '+50422325855', 'info.honduras@swisscontact.org');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clientes_saas`
--

CREATE TABLE `clientes_saas` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `alias` varchar(250) NOT NULL,
  `subdominio` varchar(100) DEFAULT NULL,
  `rtn` varchar(25) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `og_image_url` varchar(500) DEFAULT NULL,
  `favicon_url` varchar(500) DEFAULT NULL,
  `apple_touch_icon_url` varchar(500) DEFAULT NULL,
  `direccion` varchar(250) NOT NULL,
  `razon_social` varchar(255) DEFAULT NULL,
  `estado` enum('activo','inactivo') DEFAULT 'activo',
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `tipo_plan` varchar(50) DEFAULT 'basico',
  `certificado_digital` varchar(255) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `clientes_saas`
--

INSERT INTO `clientes_saas` (`id`, `nombre`, `alias`, `subdominio`, `rtn`, `email`, `logo_url`, `og_image_url`, `favicon_url`, `apple_touch_icon_url`, `direccion`, `razon_social`, `estado`, `fecha_creacion`, `tipo_plan`, `certificado_digital`, `telefono`) VALUES
(1, 'Cámara de Comercio e Industrias de Cortés', '', 'ccic', '05019002057892', 'gerencia@ccichonduras.org', 'https://www.naranjaymediahn.com/wp-content/uploads/2023/03/logo-naranja.svg', NULL, NULL, NULL, '', NULL, 'activo', '2025-06-20 16:51:11', 'basico', NULL, NULL),
(2, 'NARANJA & MEDIA ADVERTISING AND DEVELOPMENT S. DE R. L. DE C. V.', 'Naranja & Media', 'naranjaymedia', '08019022406144', 'naranjaymediahn@gmail.com', 'https://www.naranjaymediahn.com/wp-content/uploads/2023/03/logo-naranja.svg', 'https://www.naranjaymediahn.com/wp-content/uploads/2023/03/Naranja-y-Media-General-ppt.jpg', 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-32x32-1.ico#3700', 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-192x192-1.ico#3699', 'Bo. El Carmen, frente al Instituto Omega, casa de esquina, Siguatepeque, Comayagua, Honduras, C.A.', 'NARANJA & MEDIA ADVERTISING AND DEVELOPMENT S. DE R. L. DE C. V.', 'activo', '2025-06-20 16:57:08', 'basic', NULL, '31828143');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `colaboradores`
--

CREATE TABLE `colaboradores` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellido` varchar(100) NOT NULL,
  `puesto` varchar(150) NOT NULL,
  `departamento` varchar(100) DEFAULT NULL,
  `dpi` varchar(20) DEFAULT NULL COMMENT 'Documento Personal de Identificación',
  `telefono` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `salario_base` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Salario bruto mensual en Lempiras',
  `tipo_pago` enum('mensual','quincenal') NOT NULL DEFAULT 'quincenal',
  `dia_pago` tinyint(3) UNSIGNED DEFAULT NULL COMMENT '1er día del mes para pago',
  `dia_pago_2` tinyint(3) UNSIGNED DEFAULT NULL COMMENT '2do día del mes (solo quincenal)',
  `aplica_ihss` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=sí descuenta IHSS',
  `aplica_rap` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=sí descuenta RAP',
  `categoria_gasto_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK a categorias_gastos para vincular pagos',
  `fecha_ingreso` date NOT NULL,
  `fecha_baja` date DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `banco` varchar(150) DEFAULT NULL COMMENT 'Banco donde recibe pago',
  `ciudad` varchar(100) DEFAULT NULL COMMENT 'Ciudad de residencia',
  `url_firma` varchar(400) DEFAULT NULL COMMENT 'Ruta relativa a imagen de firma. Ej: firma_21.png (guardada en includes/colaboradores/)',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `usuario_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `firma` varchar(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `colaboradores`
--

INSERT INTO `colaboradores` (`id`, `cliente_id`, `nombre`, `apellido`, `puesto`, `departamento`, `dpi`, `telefono`, `email`, `salario_base`, `tipo_pago`, `dia_pago`, `dia_pago_2`, `aplica_ihss`, `aplica_rap`, `categoria_gasto_id`, `fecha_ingreso`, `fecha_baja`, `notas`, `banco`, `ciudad`, `url_firma`, `activo`, `usuario_id`, `created_at`, `updated_at`, `firma`) VALUES
(1, 2, 'Danny Sinoé', 'Velásquez Cadenas', 'Gerente General', 'Gerencia', '0801198907280', '31828143', 'sinoeproducciones@gmail.com', 24000.00, 'quincenal', 15, 30, 0, 0, 16, '2025-03-16', NULL, NULL, NULL, NULL, NULL, 1, 7, '2026-02-24 15:15:28', '2026-02-26 06:56:33', ''),
(2, 2, 'Carlos Jafeth', 'Padilla', 'Content Manager & Digital Producer', 'Marketing', '0301200001429', '88358593', 'jafethps83@gmail.com', 15000.00, 'quincenal', 15, 30, 0, 0, 16, '2025-06-01', NULL, NULL, NULL, NULL, NULL, 1, 7, '2026-02-24 15:23:11', '2026-02-24 15:23:11', ''),
(3, 2, 'Jazmin Alejandra', 'Andreus Osorio', 'Diseñador Gráfico', 'Marketing', '0823199900115', '89312636', 'jzmnandreus@gmail.com', 7000.00, 'quincenal', 15, 30, 0, 0, 16, '2025-07-16', NULL, NULL, NULL, NULL, NULL, 1, 7, '2026-02-24 15:27:21', '2026-02-24 15:27:21', ''),
(5, 2, 'Alba Isabel', 'Gusman Ordoñez', 'Diseñador Gráfica', 'Marketing', '0801199707291', '‪+50497578245‬', 'enjugusman27@gmail.com', 8000.00, 'quincenal', 15, 30, 0, 0, 16, '2024-10-16', '2026-02-26', NULL, NULL, NULL, NULL, 0, 7, '2026-02-26 05:06:06', '2026-02-26 07:07:03', '');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `colaborador_prestamos`
--

CREATE TABLE `colaborador_prestamos` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `colaborador_id` int(11) NOT NULL,
  `tipo` enum('prestamo','adelanto','bono','viatico','multa') DEFAULT NULL,
  `monto_total` decimal(10,2) NOT NULL,
  `saldo_pendiente` decimal(10,2) NOT NULL,
  `descripcion` varchar(300) NOT NULL,
  `fecha` date NOT NULL,
  `num_cuotas` int(11) DEFAULT 1,
  `frecuencia_cuota` enum('quincenal','mensual') DEFAULT 'mensual',
  `monto_cuota` decimal(10,2) DEFAULT NULL,
  `descuento_auto` tinyint(1) NOT NULL DEFAULT 0,
  `estado` enum('activo','pagado','cancelado') NOT NULL DEFAULT 'activo',
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `colaborador_prestamos`
--

INSERT INTO `colaborador_prestamos` (`id`, `cliente_id`, `colaborador_id`, `tipo`, `monto_total`, `saldo_pendiente`, `descripcion`, `fecha`, `num_cuotas`, `frecuencia_cuota`, `monto_cuota`, `descuento_auto`, `estado`, `notas`, `created_at`, `updated_at`) VALUES
(13, 2, 2, 'prestamo', 10800.00, 0.00, 'Prestamo para compra de celular Junio 2025', '2025-06-01', 12, 'quincenal', 900.00, 1, 'pagado', '', '2026-02-26 05:46:47', '2026-03-26 05:07:47'),
(14, 2, 2, 'prestamo', 6000.00, 0.00, 'Adelanto de Salario Octubre 2025', '2025-10-24', 12, 'quincenal', 500.00, 1, 'pagado', '', '2026-02-26 05:47:57', '2026-03-30 15:58:47'),
(16, 2, 1, 'bono', 4000.00, 0.00, 'Bono Navideño', '2026-02-15', 0, 'quincenal', 0.00, 0, 'pagado', ' | Aplicado en nómina gasto #186 el 2025-12-15', '2026-02-26 06:15:51', '2026-02-26 06:51:10'),
(17, 2, 2, 'prestamo', 500.00, 0.00, 'Adelantado Julio 2025', '2025-07-01', 1, 'quincenal', 500.00, 1, 'pagado', '', '2026-02-26 07:03:36', '2026-02-26 07:06:07'),
(18, 2, 2, 'bono', 6000.00, 0.00, 'Bono Navideño', '2025-12-15', 0, 'mensual', 0.00, 0, 'pagado', ' | Aplicado en nómina gasto #192 el 2025-12-15', '2026-02-26 07:09:17', '2026-02-26 07:19:05'),
(19, 2, 2, 'prestamo', 900.00, 0.00, 'Cuota erronea Celular', '2025-12-15', 1, 'quincenal', 900.00, 1, 'pagado', '', '2026-02-26 07:17:50', '2026-02-26 07:19:05'),
(20, 2, 2, 'prestamo', 7000.00, 5500.00, 'Adelanto de Salario Febrero 2026', '2026-02-20', 14, 'quincenal', 500.00, 1, 'activo', '', '2026-03-02 18:21:41', '2026-03-30 15:58:47'),
(22, 2, 2, 'viatico', 2100.00, 0.00, 'PAGO DE VIATICOS 2025', '2026-01-15', 0, 'mensual', 0.00, 0, 'pagado', ' | Aplicado en nómina gasto #226 el 2026-01-15', '2026-03-26 20:55:04', '2026-03-26 21:02:52');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `colaborador_prestamo_cuotas`
--

CREATE TABLE `colaborador_prestamo_cuotas` (
  `id` int(11) NOT NULL,
  `prestamo_id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `colaborador_id` int(11) NOT NULL,
  `numero_cuota` int(11) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `fecha_esperada` date NOT NULL,
  `estado` enum('pendiente','pagado','cancelado') NOT NULL DEFAULT 'pendiente',
  `fecha_pago` date DEFAULT NULL,
  `metodo_pago` enum('efectivo','transferencia','cheque','tarjeta','descuento_nomina','otro') DEFAULT NULL,
  `notas` varchar(300) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `colaborador_prestamo_cuotas`
--

INSERT INTO `colaborador_prestamo_cuotas` (`id`, `prestamo_id`, `cliente_id`, `colaborador_id`, `numero_cuota`, `monto`, `fecha_esperada`, `estado`, `fecha_pago`, `metodo_pago`, `notas`, `created_at`) VALUES
(134, 13, 2, 2, 1, 900.00, '2025-06-15', 'pagado', '2025-06-15', 'descuento_nomina', ' | Descontado en nómina gasto #142', '2026-02-26 05:46:47'),
(135, 13, 2, 2, 2, 900.00, '2025-06-30', 'pagado', '2025-06-30', 'descuento_nomina', ' | Descontado en nómina gasto #147', '2026-02-26 05:46:47'),
(136, 13, 2, 2, 3, 900.00, '2025-07-15', 'pagado', '2025-07-15', 'descuento_nomina', ' | Descontado en nómina gasto #148 | Descontado en nómina gasto #190', '2026-02-26 05:46:47'),
(137, 13, 2, 2, 4, 900.00, '2025-07-30', 'pagado', '2025-08-30', 'descuento_nomina', ' | Descontado en nómina gasto #152', '2026-02-26 05:46:47'),
(138, 13, 2, 2, 5, 900.00, '2025-08-14', 'pagado', '2025-09-30', 'descuento_nomina', ' | Descontado en nómina gasto #157', '2026-02-26 05:46:47'),
(139, 13, 2, 2, 6, 900.00, '2025-08-29', 'pagado', '2025-09-15', 'descuento_nomina', ' | Descontado en nómina gasto #159', '2026-02-26 05:46:47'),
(140, 13, 2, 2, 7, 900.00, '2025-09-13', 'pagado', '2025-10-15', 'descuento_nomina', ' | Descontado en nómina gasto #160', '2026-02-26 05:46:47'),
(141, 13, 2, 2, 8, 900.00, '2025-09-28', 'pagado', '2025-11-15', 'descuento_nomina', ' | Descontado en nómina gasto #164', '2026-02-26 05:46:47'),
(142, 13, 2, 2, 9, 900.00, '2025-10-13', 'pagado', '2025-10-30', 'descuento_nomina', ' | Descontado en nómina gasto #165', '2026-02-26 05:46:47'),
(143, 13, 2, 2, 10, 900.00, '2025-10-28', 'pagado', '2025-11-14', 'descuento_nomina', ' | Descontado en nómina gasto #168', '2026-02-26 05:46:47'),
(144, 13, 2, 2, 11, 900.00, '2025-11-12', 'pagado', '2025-11-15', 'descuento_nomina', ' | Descontado en nómina gasto #170', '2026-02-26 05:46:47'),
(145, 13, 2, 2, 12, 900.00, '2025-11-27', 'pagado', '2025-11-28', 'descuento_nomina', ' | Descontado en nómina gasto #172', '2026-02-26 05:46:47'),
(146, 14, 2, 2, 1, 500.00, '2025-10-30', 'pagado', '2025-11-15', 'descuento_nomina', ' | Descontado en nómina gasto #164', '2026-02-26 05:47:57'),
(147, 14, 2, 2, 2, 500.00, '2025-11-14', 'pagado', '2025-10-30', 'descuento_nomina', ' | Descontado en nómina gasto #165', '2026-02-26 05:47:57'),
(148, 14, 2, 2, 3, 500.00, '2025-11-29', 'pagado', '2025-11-14', 'descuento_nomina', ' | Descontado en nómina gasto #168', '2026-02-26 05:47:57'),
(149, 14, 2, 2, 4, 500.00, '2025-12-14', 'pagado', '2025-11-15', 'descuento_nomina', ' | Descontado en nómina gasto #170', '2026-02-26 05:47:57'),
(150, 14, 2, 2, 5, 500.00, '2025-12-29', 'pagado', '2025-11-28', 'descuento_nomina', ' | Descontado en nómina gasto #172', '2026-02-26 05:47:57'),
(151, 14, 2, 2, 6, 500.00, '2026-01-13', 'pagado', '2026-01-15', 'descuento_nomina', ' | Descontado en nómina gasto #176 | Descontado en nómina gasto #226', '2026-02-26 05:47:57'),
(152, 14, 2, 2, 7, 500.00, '2026-01-28', 'pagado', '2025-12-15', 'descuento_nomina', ' | Descontado en nómina gasto #192', '2026-02-26 05:47:57'),
(153, 14, 2, 2, 8, 500.00, '2026-02-12', 'pagado', '2026-01-30', 'descuento_nomina', ' | Descontado en nómina gasto #198', '2026-02-26 05:47:57'),
(154, 14, 2, 2, 9, 500.00, '2026-02-27', 'pagado', '2026-02-28', 'descuento_nomina', ' | Descontado en nómina gasto #220', '2026-02-26 05:47:57'),
(155, 14, 2, 2, 10, 500.00, '2026-03-14', 'pagado', '2026-03-13', 'descuento_nomina', ' | Descontado en nómina gasto #223', '2026-02-26 05:47:57'),
(156, 14, 2, 2, 11, 500.00, '2026-03-29', 'pagado', '2026-03-30', 'descuento_nomina', ' | Descontado en nómina gasto #228', '2026-02-26 05:47:57'),
(157, 14, 2, 2, 12, 500.00, '2026-04-13', 'pendiente', NULL, NULL, NULL, '2026-02-26 05:47:57'),
(170, 17, 2, 2, 1, 500.00, '2025-07-15', 'pagado', '2025-07-15', 'descuento_nomina', ' | Descontado en nómina gasto #190', '2026-02-26 07:03:36'),
(171, 19, 2, 2, 1, 900.00, '2026-02-26', 'pagado', '2025-12-15', 'descuento_nomina', ' | Descontado en nómina gasto #192', '2026-02-26 07:17:50'),
(172, 20, 2, 2, 1, 500.00, '2026-02-28', 'pagado', '2026-02-28', 'descuento_nomina', ' | Descontado en nómina gasto #220', '2026-03-02 18:21:41'),
(173, 20, 2, 2, 2, 500.00, '2026-03-15', 'pagado', '2026-03-13', 'descuento_nomina', ' | Descontado en nómina gasto #223', '2026-03-02 18:21:41'),
(174, 20, 2, 2, 3, 500.00, '2026-03-30', 'pagado', '2026-03-30', 'descuento_nomina', ' | Descontado en nómina gasto #228', '2026-03-02 18:21:41'),
(175, 20, 2, 2, 4, 500.00, '2026-04-14', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41'),
(176, 20, 2, 2, 5, 500.00, '2026-04-29', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41'),
(177, 20, 2, 2, 6, 500.00, '2026-05-14', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41'),
(178, 20, 2, 2, 7, 500.00, '2026-05-29', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41'),
(179, 20, 2, 2, 8, 500.00, '2026-06-13', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41'),
(180, 20, 2, 2, 9, 500.00, '2026-06-28', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41'),
(181, 20, 2, 2, 10, 500.00, '2026-07-13', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41'),
(182, 20, 2, 2, 11, 500.00, '2026-07-28', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41'),
(183, 20, 2, 2, 12, 500.00, '2026-08-12', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41'),
(184, 20, 2, 2, 13, 500.00, '2026-08-27', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41'),
(185, 20, 2, 2, 14, 500.00, '2026-09-11', 'pendiente', NULL, NULL, NULL, '2026-03-02 18:21:41');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion_sistema`
--

CREATE TABLE `configuracion_sistema` (
  `id` int(11) NOT NULL,
  `nombre_empresa` varchar(150) DEFAULT NULL,
  `rtn` varchar(25) DEFAULT NULL,
  `telefono` varchar(25) DEFAULT NULL,
  `correo` varchar(100) DEFAULT NULL,
  `direccion` text DEFAULT NULL,
  `certificador_nombre` varchar(150) DEFAULT NULL,
  `certificador_rtn` varchar(25) DEFAULT NULL,
  `numero_certificado` varchar(50) DEFAULT NULL,
  `footer_factura` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `configuracion_sistema`
--

INSERT INTO `configuracion_sistema` (`id`, `nombre_empresa`, `rtn`, `telefono`, `correo`, `direccion`, `certificador_nombre`, `certificador_rtn`, `numero_certificado`, `footer_factura`) VALUES
(1, 'Naranja y Media Advertising and Development', '161-24-10500-33', '+504 3182-8143', 'naranjaymediahn@gmail.com', 'Bo. El Carmen, frente al Instituto Omega, Siguatepeque, Comayagua, Honduras', 'Naranja y Media Advertising and Development', '08019022406144', 'RFI 161-24-10500-33', 'Sistema de facturación certificado por Naranja y Media.'),
(2, 'Gráficos de Occidente', '04019004010909', '2662-0198/31794022', NULL, NULL, NULL, '9231-23-10500-105', '9231-23-10500-105', 'Sistema de facturación certificado por Naranja y Media.');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contratos`
--

CREATE TABLE `contratos` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `receptor_id` int(11) NOT NULL,
  `producto_id` int(11) DEFAULT NULL,
  `nombre_contrato` varchar(200) NOT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Total de todos los servicios del contrato',
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `dia_pago` tinyint(2) NOT NULL DEFAULT 1,
  `estado` enum('activo','vencido','cancelado','pausado') DEFAULT 'activo',
  `tipo_contrato` enum('estandar','periodico','rotativo','sin_factura') NOT NULL DEFAULT 'estandar',
  `frecuencia_meses` tinyint(3) UNSIGNED DEFAULT NULL,
  `mes_inicio_ciclo` tinyint(3) UNSIGNED DEFAULT NULL,
  `concepto_recibo` varchar(300) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `contratos`
--

INSERT INTO `contratos` (`id`, `cliente_id`, `receptor_id`, `producto_id`, `nombre_contrato`, `monto`, `fecha_inicio`, `fecha_fin`, `dia_pago`, `estado`, `tipo_contrato`, `frecuencia_meses`, `mes_inicio_ciclo`, `concepto_recibo`, `notas`, `created_at`) VALUES
(10, 2, 28, 90, 'Servicios Web Administrados y Soporte Operativo', 25000.00, '2026-02-01', '2026-08-30', 30, 'activo', 'estandar', NULL, NULL, NULL, '', '2026-02-22 15:06:48'),
(11, 2, 17, 32, 'Generación de estrategia, Manejo de redes Sociales y todas las publicaciones, manejo de pauta y segmentacion, SEM, SEO y landing pages', 11500.00, '2024-06-01', '2026-02-28', 30, 'vencido', 'estandar', NULL, NULL, NULL, 'Cambios por mejoras', '2026-02-22 15:08:42'),
(12, 2, 17, 92, 'Generación de estrategia, Manejo de redes Sociales y todas las publicaciones, manejo de pauta y segmentacion, SEM, SEO y landing pages', 14000.00, '2026-03-01', '2027-03-31', 30, 'activo', 'estandar', NULL, NULL, NULL, '', '2026-02-22 15:47:23'),
(13, 2, 6, 37, 'Estrategia de Marketing Digital, Generación de Contenido y Manejo de Pauta en Social Media.', 9000.00, '2025-03-10', '2027-01-10', 10, 'activo', 'estandar', NULL, NULL, NULL, '', '2026-02-22 15:48:32'),
(14, 2, 27, 93, 'ESTRATEGIA DE MARKETING DIGITAL MARZO - ABRIL 2026', 17391.30, '2026-03-01', '2026-08-30', 30, 'activo', 'estandar', NULL, NULL, NULL, '', '2026-02-25 16:56:33'),
(16, 2, 17, 94, 'Soporte y Mantenimiento Técnico del CRM / Automatizaciones', 4000.00, '2026-03-01', '2027-03-31', 30, 'activo', 'estandar', NULL, NULL, NULL, '', '2026-03-12 15:24:42'),
(18, 2, 29, 96, 'CONSULTORIA CAMPAÑAS DE MARKETING', 26000.00, '2026-03-01', '2026-08-30', 30, 'activo', 'sin_factura', NULL, NULL, 'CONSULTORIA CAMPAÑAS DE MARKETING', '', '2026-03-27 22:04:28'),
(21, 2, 25, 75, 'Generación de estrategia, Manejo de redes Sociales y todas las publicaciones, manejo de pauta y segmentacion, SEM, SEO y landing pages', 15500.00, '2025-02-01', '2026-12-30', 30, 'activo', 'rotativo', 2, 2, NULL, '', '2026-03-27 23:44:52'),
(22, 2, 1, 1, 'Soporte y Mantenimiento Web y Gestión de Redes Sociales', 10000.00, '2026-04-15', '2026-12-15', 15, 'activo', 'estandar', NULL, NULL, NULL, '', '2026-03-28 00:16:55'),
(23, 2, 30, 97, 'CONTRATO PARA LA PRESTACIÓN DE SERVICIOS TÉCNICOS PUNTUALES', 20250.00, '2026-03-26', '2026-04-26', 26, 'activo', 'sin_factura', NULL, NULL, 'Capacitaciones', '', '2026-03-28 02:18:13'),
(24, 2, 27, 99, 'Soporte y Mantenimiento para el Portal Financiero', 5000.00, '2026-04-01', '2026-09-30', 30, 'activo', 'estandar', NULL, NULL, NULL, '', '2026-03-28 04:43:33'),
(25, 2, 27, 98, 'Soporte y Mantenimiento para el Sitio Web', 5000.00, '2026-04-01', '2026-09-30', 30, 'activo', 'estandar', NULL, NULL, NULL, '', '2026-03-28 04:44:06');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contratos_clientes_rotativos`
--

CREATE TABLE `contratos_clientes_rotativos` (
  `id` int(11) NOT NULL,
  `contrato_id` int(11) NOT NULL,
  `receptor_id` int(11) NOT NULL,
  `orden` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `contratos_clientes_rotativos`
--

INSERT INTO `contratos_clientes_rotativos` (`id`, `contrato_id`, `receptor_id`, `orden`, `monto`, `activo`, `created_at`) VALUES
(22, 21, 25, 0, 15500.00, 1, '2026-03-27 23:44:52'),
(23, 21, 5, 1, 15500.00, 1, '2026-03-27 23:44:52'),
(24, 21, 2, 2, 15500.00, 1, '2026-03-27 23:44:52');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contratos_recibos`
--

CREATE TABLE `contratos_recibos` (
  `id` int(11) NOT NULL,
  `contrato_id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `receptor_id` int(11) NOT NULL,
  `numero_recibo` varchar(30) NOT NULL,
  `descripcion` varchar(300) DEFAULT NULL,
  `fecha_emision` date NOT NULL,
  `periodo_mes` tinyint(3) UNSIGNED DEFAULT NULL,
  `periodo_anio` smallint(5) UNSIGNED DEFAULT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `concepto` varchar(500) NOT NULL,
  `metodo_pago` enum('efectivo','transferencia','cheque','tarjeta','otro') NOT NULL DEFAULT 'transferencia',
  `estado` enum('emitido','anulado') NOT NULL DEFAULT 'emitido',
  `archivo_url` varchar(500) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `contratos_recibos`
--

INSERT INTO `contratos_recibos` (`id`, `contrato_id`, `cliente_id`, `receptor_id`, `numero_recibo`, `descripcion`, `fecha_emision`, `periodo_mes`, `periodo_anio`, `monto`, `concepto`, `metodo_pago`, `estado`, `archivo_url`, `notas`, `usuario_id`, `creado_en`, `created_at`, `updated_at`) VALUES
(1, 23, 2, 30, '1', NULL, '2026-03-27', 3, 2026, 20250.00, 'CONTRATO PARA LA PRESTACIÓN DE SERVICIOS TÉCNICOS PUNTUALES', 'transferencia', 'emitido', NULL, NULL, 7, '2026-03-27 22:58:42', '2026-03-28 04:58:42', '2026-03-28 04:58:42'),
(2, 23, 2, 30, '2', NULL, '2026-03-27', 3, 2026, 20250.00, 'CONTRATO PARA LA PRESTACIÓN DE SERVICIOS TÉCNICOS PUNTUALES', 'transferencia', 'emitido', NULL, NULL, 7, '2026-03-27 22:59:13', '2026-03-28 04:59:13', '2026-03-28 04:59:13');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contratos_recibos_contador`
--

CREATE TABLE `contratos_recibos_contador` (
  `cliente_id` int(11) NOT NULL,
  `anio` smallint(5) UNSIGNED NOT NULL,
  `ultimo_numero` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contratos_servicios`
--

CREATE TABLE `contratos_servicios` (
  `id` int(11) NOT NULL,
  `contrato_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `contratos_servicios`
--

INSERT INTO `contratos_servicios` (`id`, `contrato_id`, `producto_id`, `monto`) VALUES
(1, 10, 90, 25000.00),
(3, 12, 92, 14000.00),
(7, 11, 32, 11500.00),
(10, 15, 54, 28571.43),
(11, 16, 94, 4000.00),
(13, 14, 93, 17391.30),
(16, 13, 37, 9000.00),
(17, 18, 96, 26000.00),
(24, 21, 75, 15500.00),
(25, 22, 1, 3000.00),
(26, 22, 36, 7000.00),
(29, 23, 97, 20250.00),
(30, 24, 99, 5000.00),
(31, 25, 98, 5000.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `departamentos`
--

CREATE TABLE `departamentos` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `pais_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `departamentos`
--

INSERT INTO `departamentos` (`id`, `nombre`, `pais_id`) VALUES
(1, 'Atlántida', NULL),
(2, 'Colón', NULL),
(3, 'Comayagua', NULL),
(4, 'Copán', NULL),
(5, 'Cortés', NULL),
(6, 'Choluteca', NULL),
(7, 'El Paraíso', NULL),
(8, 'Francisco Morazán', NULL),
(9, 'Gracias a Dios', NULL),
(10, 'Intibucá', NULL),
(11, 'Islas de la Bahía', NULL),
(12, 'La Paz', NULL),
(13, 'Lempira', NULL),
(14, 'Ocotepeque', NULL),
(15, 'Olancho', NULL),
(16, 'Santa Bárbara', NULL),
(17, 'Valle', NULL),
(18, 'Yoro', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalle_productos`
--

CREATE TABLE `detalle_productos` (
  `id` int(11) NOT NULL,
  `factura_id` int(11) NOT NULL,
  `producto` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `cantidad` int(11) NOT NULL,
  `precio_unitario` decimal(15,2) NOT NULL,
  `subtotal` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `establecimientos`
--

CREATE TABLE `establecimientos` (
  `establecimiento_id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `codigo_establecimiento` varchar(3) DEFAULT NULL,
  `codigo_punto` varchar(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `establecimientos`
--

INSERT INTO `establecimientos` (`establecimiento_id`, `cliente_id`, `nombre`, `codigo_establecimiento`, `codigo_punto`) VALUES
(1, 2, 'Siguatepeque', '001', '01'),
(2, 2, 'Tegucigalpa', '002', '02'),
(3, 1, 'San Pedro Sula', '001', '01'),
(4, 1, 'San Pedro Sula', '001', '01'),
(5, 1, 'Puerto Cortés', '002', '01'),
(6, 1, 'La Lima', '003', '01'),
(7, 1, 'Villanueva', '004', '01'),
(8, 1, 'Choloma', '005', '01'),
(10, 2, 'Tegucigalpa', '002', '01'),
(11, 2, 'Comayagua', '003', '01'),
(12, 2, 'La Ceiba', '004', '01'),
(13, 2, 'Danlí', '005', '01');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `facturas`
--

CREATE TABLE `facturas` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `establecimiento_id` int(11) NOT NULL,
  `cai_id` int(11) DEFAULT NULL,
  `receptor_id` int(11) DEFAULT NULL,
  `contrato_id` int(11) DEFAULT NULL,
  `receptor_rotativo_id` int(11) DEFAULT NULL,
  `correlativo` varchar(25) DEFAULT NULL,
  `fecha_emision` datetime DEFAULT current_timestamp(),
  `condicion_pago` enum('credito','contado','credito/contado') NOT NULL DEFAULT 'contado',
  `exonerado` tinyint(1) DEFAULT 0,
  `orden_compra_exenta` varchar(50) DEFAULT NULL,
  `constancia_exoneracion` varchar(50) DEFAULT NULL,
  `registro_sag` varchar(50) DEFAULT NULL,
  `gravado_total` decimal(10,2) DEFAULT 0.00,
  `exento_total` decimal(10,2) DEFAULT 0.00,
  `importe_exonerado` decimal(10,2) DEFAULT 0.00,
  `importe_gravado_15` decimal(10,2) DEFAULT 0.00,
  `importe_gravado_18` decimal(10,2) DEFAULT 0.00,
  `subtotal` decimal(10,2) DEFAULT NULL,
  `isv_15` decimal(10,2) DEFAULT 0.00,
  `isv_18` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) DEFAULT NULL,
  `monto_letras` text DEFAULT NULL,
  `pdf_url` varchar(255) DEFAULT NULL,
  `estado` enum('emitida','anulada','borrador') DEFAULT 'emitida',
  `estado_declarada` tinyint(1) NOT NULL DEFAULT 0,
  `pagada` tinyint(1) NOT NULL DEFAULT 0,
  `enviada_receptor` tinyint(1) NOT NULL DEFAULT 1,
  `periodo_mes` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Mes del período que cubre esta factura (1-12)',
  `periodo_anio` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Año del período que cubre esta factura'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `facturas`
--

INSERT INTO `facturas` (`id`, `cliente_id`, `establecimiento_id`, `cai_id`, `receptor_id`, `contrato_id`, `receptor_rotativo_id`, `correlativo`, `fecha_emision`, `condicion_pago`, `exonerado`, `orden_compra_exenta`, `constancia_exoneracion`, `registro_sag`, `gravado_total`, `exento_total`, `importe_exonerado`, `importe_gravado_15`, `importe_gravado_18`, `subtotal`, `isv_15`, `isv_18`, `total`, `monto_letras`, `pdf_url`, `estado`, `estado_declarada`, `pagada`, `enviada_receptor`, `periodo_mes`, `periodo_anio`) VALUES
(1, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000001', '2025-02-15 00:00:00', 'contado', 1, NULL, NULL, NULL, 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Mil trescientos cincuenta Lempiras Exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(2, 2, 1, 1, 2, 21, NULL, '000-002-01-00000002', '2025-02-17 00:00:00', 'contado', 0, NULL, NULL, NULL, 16966.91, 0.00, 0.00, 16966.91, 0.00, 16966.91, 2545.04, 0.00, 19511.95, 'Diecinueve mil quinientos once Lempiras con 95/100', NULL, 'emitida', 1, 1, 1, 2, 2025),
(3, 2, 1, 1, 2, NULL, NULL, '000-002-01-00000003', '2025-03-01 00:00:00', 'contado', 0, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, 0.00, 2000.00, 300.00, 0.00, 2300.00, 'Dos mil trescientos Lempiras', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(4, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000004', '2025-03-14 00:00:00', 'contado', 0, NULL, NULL, NULL, 11050.00, 0.00, 0.00, 11050.00, 0.00, 11050.00, 1657.50, 0.00, 12707.50, 'Doce mil setecientos siete Lempiras con 50/100', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(5, 2, 1, 1, 3, NULL, NULL, '000-002-01-00000005', '2025-03-14 00:00:00', 'contado', 0, NULL, NULL, NULL, 29350.00, 0.00, 0.00, 29350.00, 0.00, 29350.00, 4402.50, 0.00, 33752.50, 'Treinta y tres mil setecientos cincuenta y dos Lempiras con 50/100', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(6, 2, 1, 1, 4, NULL, NULL, '000-002-01-00000006', '2025-04-14 00:00:00', 'contado', 0, NULL, NULL, NULL, 37195.00, 0.00, 0.00, 37195.00, 0.00, 37195.00, 5579.25, 0.00, 42774.25, 'Cuarenta y dos mil setecientos setenta y cuatro Lempiras con 25/100', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(7, 2, 1, 1, 5, 21, NULL, '000-002-01-00000007', '2025-04-14 00:00:00', 'contado', 0, '', '', '', 15500.00, 0.00, 0.00, 15500.00, 0.00, 15500.00, 2325.00, 0.00, 17825.00, 'Diecisiete mil ochocientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 3, 2025),
(8, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000008', '2025-05-14 00:00:00', 'contado', 0, NULL, NULL, NULL, 7325.00, 0.00, 0.00, 7325.00, 0.00, 7325.00, 1098.75, 0.00, 8423.75, 'Ocho mil cuatrocientos veintitrés Lempiras con 75/100', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(9, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000009', '2025-05-14 00:00:00', 'contado', 0, NULL, NULL, NULL, 12666.10, 0.00, 0.00, 12666.10, 0.00, 12666.10, 1899.92, 0.00, 14566.02, 'Catorce mil quinientos sesenta y seis Lempiras con 02/100', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(10, 2, 1, 1, 6, 13, NULL, '000-002-01-00000010', '2025-05-14 00:00:00', 'contado', 0, NULL, NULL, NULL, 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta Lempiras', NULL, 'emitida', 1, 1, 1, 3, 2025),
(11, 2, 1, 1, 6, 13, NULL, '000-002-01-00000011', '2025-05-14 00:00:00', 'contado', 0, NULL, NULL, NULL, 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta Lempiras', NULL, 'emitida', 1, 1, 1, 4, 2025),
(12, 2, 1, 1, 6, 13, NULL, '000-002-01-00000012', '2025-06-09 00:00:00', 'contado', 0, NULL, NULL, NULL, 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta Lempiras', NULL, 'emitida', 1, 1, 1, 5, 2025),
(13, 2, 1, 1, 5, 21, NULL, '000-002-01-00000013', '2025-06-09 00:00:00', 'contado', 0, NULL, NULL, NULL, 15500.00, 0.00, 0.00, 15500.00, 0.00, 15500.00, 2325.00, 0.00, 17825.00, 'Diecisiete mil ochocientos veinticinco Lempiras', NULL, 'emitida', 1, 1, 1, 4, 2025),
(14, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000014', '2025-06-09 00:00:00', 'contado', 0, '', '', '', 14454.00, 0.00, 0.00, 14454.00, 0.00, 14454.00, 2168.10, 0.00, 16622.10, 'Dieciséis mil seiscientos veintidos lempiras con 10/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(28, 2, 1, 1, 7, NULL, NULL, '000-002-01-00000015', '2025-06-20 23:36:00', 'contado', 0, '', '', '', 28676.67, 0.00, 0.00, 28676.67, 0.00, 28676.67, 4301.50, 0.00, 32978.17, 'Treinta y dos mil novecientos setenta y ocho lempiras con 17/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(32, 2, 1, 1, 8, NULL, NULL, '000-002-01-00000016', '2025-06-23 10:14:00', 'contado', 0, '', '', '', 3000.00, 0.00, 0.00, 3000.00, 0.00, 3000.00, 450.00, 0.00, 3450.00, 'Tres mil cuatrocientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(52, 2, 1, 1, 17, 11, NULL, '000-002-01-00000017', '2025-06-30 14:20:00', 'contado', 0, '', '', '', 7500.00, 0.00, 0.00, 7500.00, 0.00, 7500.00, 1125.00, 0.00, 8625.00, 'Ocho mil seiscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(53, 2, 1, 1, 17, 11, NULL, '000-002-01-00000018', '2025-06-30 14:20:00', 'contado', 0, '', '', '', 7500.00, 0.00, 0.00, 7500.00, 0.00, 7500.00, 1125.00, 0.00, 8625.00, 'Ocho mil seiscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(54, 2, 1, 1, 17, 11, NULL, '000-002-01-00000019', '2025-06-30 14:21:00', 'contado', 0, '', '', '', 7500.00, 0.00, 0.00, 7500.00, 0.00, 7500.00, 1125.00, 0.00, 8625.00, 'Ocho mil seiscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(55, 2, 1, 1, 17, 11, NULL, '000-002-01-00000020', '2025-06-30 14:22:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(56, 2, 1, 1, 17, 11, NULL, '000-002-01-00000021', '2025-06-30 14:26:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(57, 2, 1, 1, 17, 11, NULL, '000-002-01-00000022', '2025-06-30 14:27:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(58, 2, 1, 1, 17, 11, NULL, '000-002-01-00000023', '2025-06-30 14:34:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(59, 2, 1, 1, 17, 11, NULL, '000-002-01-00000024', '2025-06-30 14:35:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(60, 2, 1, 1, 17, 11, NULL, '000-002-01-00000025', '2025-06-30 14:37:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(61, 2, 1, 1, 17, 11, NULL, '000-002-01-00000026', '2025-06-30 14:42:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(62, 2, 1, 1, 17, 11, NULL, '000-002-01-00000027', '2025-06-30 14:57:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(63, 2, 1, 1, 17, 11, NULL, '000-002-01-00000028', '2025-06-30 14:57:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(64, 2, 1, 1, 17, 11, NULL, '000-002-01-00000029', '2025-06-30 14:59:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(65, 2, 1, 1, 17, 11, NULL, '000-002-01-00000030', '2025-06-30 14:59:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(66, 2, 1, 1, 17, 11, NULL, '000-002-01-00000031', '2025-06-30 15:01:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(69, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000032', '2025-07-07 12:20:00', 'contado', 0, '', '', '', 11550.00, 0.00, 0.00, 11550.00, 0.00, 11550.00, 1732.50, 0.00, 13282.50, 'Trece mil doscientos ochenta y dos lempiras con 50/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(70, 2, 1, 1, 6, 13, NULL, '000-002-01-00000033', '2025-07-09 10:19:19', 'contado', 0, NULL, NULL, NULL, 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(71, 2, 1, 1, 2, 21, NULL, '000-002-01-00000034', '2025-07-10 15:07:18', 'contado', 0, NULL, NULL, NULL, 15500.00, 0.00, 0.00, 15500.00, 0.00, 15500.00, 2325.00, 0.00, 17825.00, 'Diecisiete mil ochocientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 5, 2025),
(72, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000035', '2025-07-21 17:18:51', 'contado', 0, NULL, NULL, NULL, 22500.00, 0.00, 0.00, 22500.00, 0.00, 22500.00, 3375.00, 0.00, 25875.00, 'Veinticinco mil ochocientos setenta y cinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(75, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000036', '2025-08-01 00:53:00', 'contado', 0, '', '', '', 3000.00, 0.00, 0.00, 3000.00, 0.00, 3000.00, 450.00, 0.00, 3450.00, 'Tres mil cuatrocientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(79, 2, 1, 1, 2, 21, NULL, '000-002-01-00000037', '2025-08-01 09:53:00', 'contado', 0, '', '', '', 15500.00, 0.00, 0.00, 15500.00, 0.00, 15500.00, 2325.00, 0.00, 17825.00, 'Diecisiete mil ochocientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 6, 2025),
(80, 2, 1, 1, 2, NULL, NULL, '000-002-01-00000038', '2025-08-01 12:53:00', 'contado', 0, '', '', '', 4256.35, 0.00, 0.00, 4256.35, 0.00, 4256.35, 638.45, 0.00, 4894.80, 'Cuatro mil ochocientos noventa y cuatro lempiras con 80/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(81, 2, 1, 1, 6, 13, NULL, '000-002-01-00000039', '2025-08-01 14:31:00', 'contado', 0, '', '', '', 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, 7, 2025),
(82, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000040', '2025-08-01 14:33:00', 'contado', 0, '', '', '', 14687.50, 0.00, 0.00, 14687.50, 0.00, 14687.50, 2203.13, 0.00, 16890.63, 'Dieciséis mil ochocientos noventa lempiras con 63/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(90, 2, 1, 5, 19, NULL, NULL, '000-001-01-00000151', '2024-02-13 17:01:15', 'contado', 0, NULL, NULL, NULL, 21150.00, 0.00, 0.00, 21150.00, 0.00, 21150.00, 3172.50, 0.00, 24322.50, 'Veinticuatro mil trescientos veintidos lempiras con 50/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(91, 2, 1, 5, 20, NULL, NULL, '000-001-01-00000152', '2024-06-08 17:01:28', 'contado', 0, NULL, NULL, NULL, 197000.00, 0.00, 0.00, 197000.00, 0.00, 197000.00, 29550.00, 0.00, 226550.00, 'Doscientos veintiseis mil quinientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(92, 2, 1, 5, 21, NULL, NULL, '000-001-01-00000153', '2024-03-19 17:05:07', 'contado', 0, NULL, NULL, NULL, 14000.00, 0.00, 0.00, 14000.00, 0.00, 14000.00, 2100.00, 0.00, 16100.00, 'Dieciséis mil ciento lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(93, 2, 1, 5, 17, NULL, NULL, '000-001-01-00000154', '2024-02-22 17:06:57', 'contado', 0, NULL, NULL, NULL, 5993.79, 0.00, 0.00, 5993.79, 0.00, 5993.79, 899.07, 0.00, 6892.86, 'Seis mil ochocientos noventa y dos lempiras con 86/100 centavos', NULL, 'anulada', 2, 0, 1, NULL, NULL),
(94, 2, 1, 5, 17, NULL, NULL, '000-001-01-00000155', '2024-03-22 17:13:44', 'contado', 0, NULL, NULL, NULL, 5993.79, 0.00, 0.00, 5993.79, 0.00, 5993.79, 899.07, 0.00, 6892.86, 'Seis mil ochocientos noventa y dos lempiras con 86/100 centavos', NULL, 'anulada', 2, 0, 1, NULL, NULL),
(95, 2, 1, 5, 17, NULL, NULL, '000-001-01-00000156', '2024-04-01 17:15:00', 'contado', 0, NULL, NULL, NULL, 7500.00, 0.00, 0.00, 7500.00, 0.00, 7500.00, 1125.00, 0.00, 8625.00, 'Ocho mil seiscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(96, 2, 1, 5, 17, NULL, NULL, '000-001-01-00000157', '2024-04-01 17:27:11', 'contado', 0, NULL, NULL, NULL, 7500.00, 0.00, 0.00, 7500.00, 0.00, 7500.00, 1125.00, 0.00, 8625.00, 'Ocho mil seiscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(97, 2, 1, 5, 17, NULL, NULL, '000-001-01-00000158', '2024-04-01 17:27:45', 'contado', 0, NULL, NULL, NULL, 7500.00, 0.00, 0.00, 7500.00, 0.00, 7500.00, 1125.00, 0.00, 8625.00, 'Ocho mil seiscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(98, 2, 1, 5, 17, NULL, NULL, '000-001-01-00000159', '2024-04-01 17:28:26', 'contado', 0, NULL, NULL, NULL, 7500.00, 0.00, 0.00, 7500.00, 0.00, 7500.00, 1125.00, 0.00, 8625.00, 'Ocho mil seiscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(99, 2, 1, 5, 19, NULL, NULL, '000-001-01-00000160', '2024-04-01 17:29:34', 'contado', 0, NULL, NULL, NULL, 7650.00, 0.00, 0.00, 7650.00, 0.00, 7650.00, 1147.50, 0.00, 8797.50, 'Ocho mil setecientos noventa y siete lempiras con 50/100 centavos', NULL, 'anulada', 2, 0, 1, NULL, NULL),
(100, 2, 1, 5, 19, NULL, NULL, '000-001-01-00000161', '2024-05-15 17:36:18', 'contado', 0, NULL, NULL, NULL, 7650.00, 0.00, 0.00, 7650.00, 0.00, 7650.00, 1147.50, 0.00, 8797.50, 'Ocho mil setecientos noventa y siete lempiras con 50/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(101, 2, 1, 5, 22, NULL, NULL, '000-001-01-00000162', '2024-09-30 17:38:00', 'contado', 1, 'OC202411661', 'R2023001536', '', 0.00, 0.00, 0.00, 0.00, 0.00, 24750.00, 0.00, 0.00, 24750.00, 'Veinticuatro mil setecientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(102, 2, 1, 5, 19, NULL, NULL, '000-001-01-00000163', '2024-09-30 17:38:59', 'contado', 0, NULL, NULL, NULL, 20300.00, 0.00, 0.00, 20300.00, 0.00, 20300.00, 3045.00, 0.00, 23345.00, 'Veintitres mil trescientos cuarenta y cinco lempiras exactos', NULL, 'anulada', 2, 0, 1, NULL, NULL),
(103, 2, 1, 5, 19, NULL, NULL, '000-001-01-00000164', '2024-08-21 17:41:28', 'contado', 0, NULL, NULL, NULL, 23500.00, 0.00, 0.00, 23500.00, 0.00, 23500.00, 3525.00, 0.00, 27025.00, 'Veintisiete mil veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(104, 2, 1, 5, 22, NULL, NULL, '000-001-01-00000165', '2024-08-21 17:41:28', 'contado', 0, NULL, NULL, NULL, 24750.00, 0.00, 0.00, 24750.00, 0.00, 24750.00, 3712.50, 0.00, 28462.50, 'Veintiocho mil cuatrocientos sesenta y dos lempiras con 50/100 centavos', NULL, 'anulada', 2, 0, 1, NULL, NULL),
(105, 2, 1, 5, 22, NULL, NULL, '000-001-01-00000166', '2024-08-21 17:41:28', 'contado', 0, NULL, NULL, NULL, 36750.00, 0.00, 0.00, 36750.00, 0.00, 36750.00, 5512.50, 0.00, 42262.50, 'Cuarenta y dos mil doscientos sesenta y dos lempiras con 50/100 centavos', NULL, 'anulada', 2, 0, 1, NULL, NULL),
(106, 2, 1, 5, 5, NULL, NULL, '000-001-01-00000167', '2024-09-12 17:45:28', 'contado', 0, NULL, NULL, NULL, 8800.00, 0.00, 0.00, 8800.00, 0.00, 8800.00, 1320.00, 0.00, 10120.00, 'Diez mil ciento veinte lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(107, 2, 1, 5, 1, NULL, NULL, '000-001-01-00000168', '2024-09-17 17:47:25', 'contado', 0, NULL, NULL, NULL, 26150.00, 0.00, 0.00, 26150.00, 0.00, 26150.00, 3922.50, 0.00, 30072.50, 'Treinta mil setenta y dos lempiras con 50/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(108, 2, 1, 5, 5, NULL, NULL, '000-001-01-00000169', '2024-09-17 17:47:25', 'contado', 0, NULL, NULL, NULL, 16966.91, 0.00, 0.00, 16966.91, 0.00, 16966.91, 2545.04, 0.00, 19511.95, 'Diecinueve mil quinientos once lempiras con 95/100 centavos', NULL, 'anulada', 2, 0, 1, NULL, NULL),
(109, 2, 1, 5, 5, NULL, NULL, '000-001-01-00000170', '2024-10-11 17:58:40', 'contado', 0, NULL, NULL, NULL, 16966.91, 0.00, 0.00, 16966.91, 0.00, 16966.91, 2545.04, 0.00, 19511.95, 'Diecinueve mil quinientos once lempiras con 95/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(110, 2, 1, 5, 5, NULL, NULL, '000-001-01-00000171', '2024-10-11 17:58:40', 'contado', 0, NULL, NULL, NULL, 4853.90, 0.00, 0.00, 4853.90, 0.00, 4853.90, 728.09, 0.00, 5581.99, 'Cinco mil quinientos ochenta y ún lempiras con 98/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(111, 2, 1, 5, 5, NULL, NULL, '000-001-01-00000172', '2024-10-11 17:58:40', 'contado', 0, NULL, NULL, NULL, 16966.91, 0.00, 0.00, 16966.91, 0.00, 16966.91, 2545.04, 0.00, 19511.95, 'Diecinueve mil quinientos once lempiras con 95/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(112, 2, 1, 5, 1, NULL, NULL, '000-001-01-00000173', '2024-10-14 17:58:40', 'contado', 0, '', '', '', 2725.00, 0.00, 0.00, 2725.00, 0.00, 2725.00, 408.75, 0.00, 3133.75, 'Tres mil ciento treinta y tres lempiras con 75/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(113, 2, 1, 5, 5, NULL, NULL, '000-001-01-00000174', '2024-10-21 17:58:40', 'contado', 0, NULL, NULL, NULL, 16966.91, 0.00, 0.00, 16966.91, 0.00, 16966.91, 2545.04, 0.00, 19511.95, 'Diecinueve mil quinientos once lempiras con 95/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(114, 2, 1, 5, 1, NULL, NULL, '000-001-01-00000175', '2024-11-14 17:58:40', 'contado', 0, NULL, NULL, NULL, 2025.00, 0.00, 0.00, 2025.00, 0.00, 2025.00, 303.75, 0.00, 2328.75, 'Dos mil trescientos veintiocho lempiras con 75/100 centavos', NULL, 'anulada', 2, 0, 1, NULL, NULL),
(115, 2, 1, 5, 1, NULL, NULL, '000-001-01-00000176', '2024-11-14 17:58:40', 'contado', 0, NULL, NULL, NULL, 14312.40, 0.00, 0.00, 14312.40, 0.00, 14312.40, 2146.86, 0.00, 16459.26, 'Dieciséis mil cuatrocientos cincuenta y nueve lempiras con 26/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(116, 2, 1, 5, 4, NULL, NULL, '000-001-01-00000177', '2024-11-25 17:58:40', 'contado', 0, NULL, NULL, NULL, 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(117, 2, 1, 5, 2, NULL, NULL, '000-001-01-00000178', '2024-12-12 17:58:40', 'contado', 0, NULL, NULL, NULL, 16966.91, 0.00, 0.00, 16966.91, 0.00, 16966.91, 2545.04, 0.00, 19511.95, 'Diecinueve mil quinientos once lempiras con 95/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(118, 2, 1, 5, 2, NULL, NULL, '000-001-01-00000179', '2024-12-12 17:58:40', 'contado', 0, NULL, NULL, NULL, 16966.91, 0.00, 0.00, 16966.91, 0.00, 16966.91, 2545.04, 0.00, 19511.95, 'Diecinueve mil quinientos once lempiras con 95/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(119, 2, 1, 5, 2, NULL, NULL, '000-001-01-00000180', '2024-12-12 17:58:40', 'contado', 0, NULL, NULL, NULL, 2600.00, 0.00, 0.00, 2600.00, 0.00, 2600.00, 390.00, 0.00, 2990.00, 'Dos mil novecientos noventa lempiras exactos', NULL, 'anulada', 2, 0, 1, NULL, NULL),
(120, 2, 1, 5, 1, NULL, NULL, '000-001-01-00000181', '2024-12-14 17:58:40', 'contado', 0, NULL, NULL, NULL, 8000.00, 0.00, 0.00, 8000.00, 0.00, 8000.00, 1200.00, 0.00, 9200.00, 'Nueve mil doscientos lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(121, 2, 1, 5, 23, NULL, NULL, '000-001-01-00000182', '2024-12-14 17:58:40', 'contado', 0, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'Cero lempiras exactos', NULL, 'anulada', 2, 0, 1, NULL, NULL),
(122, 2, 1, 5, 2, NULL, NULL, '000-001-01-00000183', '2024-12-14 17:58:40', 'contado', 0, NULL, NULL, NULL, 16966.91, 0.00, 0.00, 16966.91, 0.00, 16966.91, 2545.04, 0.00, 19511.95, 'Diecinueve mil quinientos once lempiras con 95/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(123, 2, 1, 5, 2, NULL, NULL, '000-001-01-00000184', '2025-01-17 18:33:35', 'contado', 0, NULL, NULL, NULL, 16966.91, 0.00, 0.00, 16966.91, 0.00, 16966.91, 2545.04, 0.00, 19511.95, 'Diecinueve mil quinientos once lempiras con 95/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(124, 2, 1, 5, 2, NULL, NULL, '000-001-01-00000185', '2025-01-17 18:33:35', 'contado', 0, NULL, NULL, NULL, 2600.00, 0.00, 0.00, 2600.00, 0.00, 2600.00, 390.00, 0.00, 2990.00, 'Dos mil novecientos noventa lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(128, 2, 1, 1, 24, NULL, NULL, '000-002-01-00000041', '2025-08-07 18:16:00', 'contado', 0, '', '', '', 2043.48, 0.00, 0.00, 2043.48, 0.00, 2043.48, 306.52, 0.00, 2350.00, 'Dos mil trescientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(129, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000042', '2025-08-09 16:17:00', 'contado', 0, '', '', '', 18000.00, 0.00, 0.00, 18000.00, 0.00, 18000.00, 2700.00, 0.00, 20700.00, 'Veinte mil setecientos lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(130, 2, 1, 1, 24, NULL, NULL, '000-002-01-00000043', '2025-08-14 13:49:00', 'contado', 0, '', '', '', 8173.91, 0.00, 0.00, 8173.91, 0.00, 8173.91, 1226.09, 0.00, 9400.00, 'Nueve mil cuatrocientos lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(131, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000044', '2025-08-16 16:03:00', 'contado', 0, '', '', '', 3000.00, 0.00, 0.00, 3000.00, 0.00, 3000.00, 450.00, 0.00, 3450.00, 'Tres mil cuatrocientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(133, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000045', '2025-08-25 10:34:00', 'contado', 0, '', '', '', 16500.00, 0.00, 0.00, 16500.00, 0.00, 16500.00, 2475.00, 0.00, 18975.00, 'Dieciocho mil novecientos setenta y cinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(135, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000046', '2025-09-02 09:36:00', 'contado', 0, '', '', '', 30727.50, 0.00, 0.00, 30727.50, 0.00, 30727.50, 4609.13, 0.00, 35336.63, 'Treinta y cinco mil trescientos treinta y seis lempiras con 63/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(136, 2, 1, 1, 17, 11, NULL, '000-002-01-00000047', '2025-09-02 11:42:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 9, 2025),
(137, 2, 1, 1, 17, 11, NULL, '000-002-01-00000048', '2025-09-02 11:43:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 9, 2025),
(138, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000049', '2025-09-09 12:21:00', 'contado', 0, '', '', '', 30000.00, 0.00, 0.00, 30000.00, 0.00, 30000.00, 4500.00, 0.00, 34500.00, 'Treinta y cuatro mil quinientos lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(139, 2, 1, 1, 6, 13, NULL, '000-002-01-00000050', '2025-09-10 14:25:00', 'contado', 0, '', '', '', 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, 8, 2025),
(140, 2, 1, 1, 25, 21, NULL, '000-002-01-00000051', '2025-09-17 18:05:00', 'contado', 0, '', '', '', 15500.00, 0.00, 0.00, 15500.00, 0.00, 15500.00, 2325.00, 0.00, 17825.00, 'Diecisiete mil ochocientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 7, 2025),
(141, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000052', '2025-10-01 16:46:00', 'contado', 0, '', '', '', 16500.00, 0.00, 0.00, 16500.00, 0.00, 16500.00, 2475.00, 0.00, 18975.00, 'Dieciocho mil novecientos setenta y cinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(142, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000053', '2025-10-01 16:47:00', 'contado', 0, '', '', '', 15479.00, 0.00, 0.00, 15479.00, 0.00, 15479.00, 2321.85, 0.00, 17800.85, 'Diecisiete mil ochocientos lempiras con 85/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(143, 2, 1, 1, 6, 13, NULL, '000-002-01-00000054', '2025-10-09 14:30:00', 'contado', 0, '', '', '', 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, 9, 2025),
(144, 2, 1, 1, 25, 21, NULL, '000-002-01-00000055', '2025-10-13 15:23:00', 'contado', 0, '', '', '', 19749.94, 0.00, 0.00, 19749.94, 0.00, 19749.94, 2962.49, 0.00, 22712.43, 'Veintidos mil setecientos doce lempiras con 43/100 centavos', NULL, 'emitida', 1, 1, 1, 8, 2025),
(145, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000056', '2025-10-13 15:29:00', 'contado', 0, '', '', '', 7050.00, 0.00, 0.00, 7050.00, 0.00, 7050.00, 1057.50, 0.00, 8107.50, 'Ocho mil ciento siete lempiras con 50/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(146, 2, 1, 1, 17, 11, NULL, '000-002-01-00000057', '2025-10-18 11:11:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 10, 2025),
(147, 2, 1, 1, 17, 11, NULL, '000-002-01-00000058', '2025-10-18 11:18:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 10, 2025),
(148, 2, 1, 1, 17, NULL, NULL, '000-002-01-00000059', '2025-10-18 11:19:00', 'contado', 0, '', '', '', 6015.41, 0.00, 0.00, 6015.41, 0.00, 6015.41, 902.30, 0.00, 6917.71, 'Seis mil novecientos diecisiete lempiras con 71/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(149, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000060', '2025-11-01 01:07:00', 'contado', 0, '', '', '', 6000.00, 0.00, 0.00, 6000.00, 0.00, 6000.00, 900.00, 0.00, 6900.00, 'Seis mil novecientos lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(150, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000061', '2025-11-01 03:54:00', 'contado', 0, '', '', '', 18636.33, 0.00, 0.00, 18636.33, 0.00, 18636.33, 2795.45, 0.00, 21431.78, 'Veintiuno mil cuatrocientos treinta y ún lempiras con 78/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(151, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000062', '2025-11-03 11:08:00', 'contado', 0, '', '', '', 27600.00, 0.00, 0.00, 27600.00, 0.00, 27600.00, 4140.00, 0.00, 31740.00, 'Treinta y uno mil setecientos cuarenta lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(152, 2, 1, 1, 6, 13, NULL, '000-002-01-00000063', '2025-12-10 00:00:00', 'contado', 0, '', '', '', 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, 10, 2025),
(153, 2, 1, 1, 5, 21, NULL, '000-002-01-00000064', '2025-11-12 11:39:00', 'contado', 0, '', '', '', 16700.00, 0.00, 0.00, 16700.00, 0.00, 16700.00, 2505.00, 0.00, 19205.00, 'Diecinueve mil doscientos cinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 9, 2025),
(156, 2, 1, 1, 17, 11, NULL, '000-002-01-00000065', '2025-11-25 18:21:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 11, 2025),
(157, 2, 1, 1, 17, 11, NULL, '000-002-01-00000066', '2025-11-25 18:55:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 11, 2025),
(158, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000067', '2025-12-02 09:43:00', 'contado', 0, '', '', '', 12800.00, 0.00, 0.00, 12800.00, 0.00, 12800.00, 1920.00, 0.00, 14720.00, 'Catorce mil setecientos veinte lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(159, 2, 1, 1, 18, NULL, NULL, '000-002-01-00000068', '2025-12-02 09:48:00', 'contado', 0, '', '', '', 7500.00, 0.00, 0.00, 7500.00, 0.00, 7500.00, 1125.00, 0.00, 8625.00, 'Ocho mil seiscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(160, 2, 1, 1, 6, 13, NULL, '000-002-01-00000069', '2025-11-10 00:00:00', 'contado', 0, '', '', '', 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, 11, 2025),
(161, 2, 1, 1, 5, 21, NULL, '000-002-01-00000070', '2025-12-16 09:31:00', 'contado', 0, '', '', '', 15500.00, 0.00, 0.00, 15500.00, 0.00, 15500.00, 2325.00, 0.00, 17825.00, 'Diecisiete mil ochocientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 10, 2025),
(162, 2, 1, 1, 26, NULL, NULL, '000-002-01-00000071', '2025-12-22 10:16:00', 'contado', 0, '', '', '', 29007.99, 0.00, 0.00, 29007.99, 0.00, 29007.99, 4351.20, 0.00, 33359.19, 'Treinta y tres mil trescientos cincuenta y nueve lempiras con 19/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(163, 2, 1, 1, 17, 11, NULL, '000-002-01-00000072', '2025-12-29 11:08:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 12, 2025),
(164, 2, 1, 1, 17, NULL, NULL, '000-002-01-00000073', '2026-01-01 11:11:00', 'contado', 0, '', '', '', 10000.00, 0.00, 0.00, 10000.00, 0.00, 10000.00, 1500.00, 0.00, 11500.00, 'Once mil quinientos lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(165, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000074', '2026-01-07 09:18:00', 'contado', 0, '', '', '', 10000.00, 0.00, 0.00, 10000.00, 0.00, 10000.00, 1500.00, 0.00, 11500.00, 'Once mil quinientos lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(166, 2, 1, 1, 17, NULL, NULL, '000-002-01-00000075', '2026-01-09 16:18:00', 'contado', 0, '', '', '', 22547.10, 0.00, 0.00, 22547.10, 0.00, 22547.10, 3382.07, 0.00, 25929.17, 'Veinticinco mil novecientos veintinueve lempiras con 17/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(168, 2, 1, 1, 6, 13, NULL, '000-002-01-00000076', '2026-01-10 22:50:00', 'contado', 0, '', '', '', 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, 12, 2025),
(171, 2, 1, 1, 2, 21, NULL, '000-002-01-00000077', '2026-01-12 10:42:00', 'contado', 0, '', '', '', 16920.00, 0.00, 0.00, 16920.00, 0.00, 16920.00, 2538.00, 0.00, 19458.00, 'Diecinueve mil cuatrocientos cincuenta y ocho lempiras exactos', NULL, 'emitida', 1, 1, 1, 11, 2025),
(172, 2, 1, 1, 1, NULL, NULL, '000-002-01-00000078', '2026-01-12 10:44:00', 'contado', 0, '', '', '', 19000.00, 0.00, 0.00, 19000.00, 0.00, 19000.00, 2850.00, 0.00, 21850.00, 'Veintiuno mil ochocientos cincuenta lempiras exactos', NULL, 'anulada', 0, 0, 0, NULL, NULL),
(173, 2, 1, 1, 17, NULL, NULL, '000-002-01-00000079', '2026-01-21 14:42:00', 'contado', 0, '', '', '', 162000.00, 0.00, 0.00, 162000.00, 0.00, 162000.00, 24300.00, 0.00, 186300.00, 'Ciento ochenta y seis mil trescientos lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(175, 2, 1, 6, 1, NULL, NULL, '000-002-01-00000101', '2026-02-02 17:37:00', 'contado', 0, '', '', '', 52819.50, 0.00, 0.00, 52819.50, 0.00, 52819.50, 7922.93, 0.00, 60742.43, 'Sesenta mil setecientos cuarenta y dos lempiras con 43/100 centavos', NULL, 'emitida', 1, 0, 1, NULL, NULL),
(176, 2, 1, 6, 25, 21, NULL, '000-002-01-00000102', '2026-02-09 12:57:00', 'contado', 0, '', '', '', 16000.00, 0.00, 0.00, 16000.00, 0.00, 16000.00, 2400.00, 0.00, 18400.00, 'Dieciocho mil cuatrocientos lempiras exactos', NULL, 'emitida', 1, 1, 1, 12, 2025),
(177, 2, 1, 6, 6, 13, NULL, '000-002-01-00000103', '2026-02-09 00:00:00', 'contado', 0, '', '', '', 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, 1, 2026),
(178, 2, 1, 6, 27, NULL, NULL, '000-002-01-00000104', '2026-02-09 15:13:00', 'contado', 0, '', '', '', 188500.00, 0.00, 0.00, 188500.00, 0.00, 188500.00, 28275.00, 0.00, 216775.00, 'Doscientos dieciséis mil setecientos setenta y cinco lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(179, 2, 1, 6, 28, 10, NULL, '000-002-01-00000105', '2026-02-16 17:17:00', 'contado', 0, '', '', '', 25000.00, 0.00, 0.00, 25000.00, 0.00, 25000.00, 3750.00, 0.00, 28750.00, 'Veintiocho mil setecientos cincuenta lempiras exactos', NULL, 'emitida', 1, 1, 1, 2, 2026),
(180, 2, 1, 6, 4, NULL, NULL, '000-002-01-00000106', '2026-02-20 11:38:00', 'contado', 0, '', '', '', 2800.00, 0.00, 0.00, 2800.00, 0.00, 2800.00, 420.00, 0.00, 3220.00, 'Tres mil doscientos veinte lempiras exactos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(182, 2, 1, 6, 17, 11, NULL, '000-002-01-00000107', '2026-02-24 15:52:00', 'contado', 0, '', '', '', 11500.00, 0.00, 0.00, 11500.00, 0.00, 11500.00, 1725.00, 0.00, 13225.00, 'Trece mil doscientos veinticinco lempiras exactos', NULL, 'emitida', 1, 1, 1, 2, 2026),
(183, 2, 1, 6, 17, NULL, NULL, '000-002-01-00000108', '2026-02-24 15:52:00', 'contado', 0, '', '', '', 5399.18, 0.00, 0.00, 5399.18, 0.00, 5399.18, 809.88, 0.00, 6209.06, 'Seis mil doscientos nueve lempiras con 06/100 centavos', NULL, 'emitida', 1, 1, 1, NULL, NULL),
(185, 2, 1, 6, 6, 13, NULL, '000-002-01-00000109', '2026-03-11 00:00:00', 'contado', 0, '', '', '', 9000.00, 0.00, 0.00, 9000.00, 0.00, 9000.00, 1350.00, 0.00, 10350.00, 'Diez mil trescientos cincuenta lempiras exactos', NULL, 'emitida', 0, 1, 1, 2, 2026),
(186, 2, 1, 6, 25, 21, NULL, '000-002-01-00000110', '2026-03-11 13:26:00', 'contado', 0, '', '', '', 16000.00, 0.00, 0.00, 16000.00, 0.00, 16000.00, 2400.00, 0.00, 18400.00, 'Dieciocho mil cuatrocientos lempiras exactos', NULL, 'emitida', 0, 0, 1, 1, 2026),
(187, 2, 1, 6, 1, NULL, NULL, '000-002-01-00000111', '2026-03-11 13:31:16', 'contado', 0, NULL, NULL, NULL, 10000.00, 0.00, 0.00, 10000.00, 0.00, 10000.00, 1500.00, 0.00, 11500.00, 'Once mil quinientos lempiras exactos', NULL, 'anulada', 0, 0, 0, NULL, NULL),
(188, 2, 1, 6, 29, NULL, NULL, '000-002-01-00000112', '2026-03-12 09:45:00', 'contado', 0, '', '', '', 8816.74, 0.00, 0.00, 8816.74, 0.00, 8816.74, 1322.52, 0.00, 10139.26, 'Diez mil ciento treinta y nueve lempiras con 26/100 centavos', NULL, 'emitida', 0, 1, 1, NULL, NULL),
(189, 2, 1, 6, 28, 10, NULL, '000-002-01-00000113', '2026-03-13 11:37:00', 'contado', 0, '', '', '', 25000.00, 0.00, 0.00, 25000.00, 0.00, 25000.00, 3750.00, 0.00, 28750.00, 'Veintiocho mil setecientos cincuenta lempiras exactos', NULL, 'emitida', 0, 1, 1, 3, 2026),
(190, 2, 1, 6, 1, NULL, NULL, '000-002-01-00000114', '2026-03-14 22:09:00', 'contado', 0, '', '', '', 17070.95, 0.00, 0.00, 17070.95, 0.00, 17070.95, 2560.64, 0.00, 19631.59, 'Diecinueve mil seiscientos treinta y ún lempiras con 59/100 centavos', NULL, 'emitida', 0, 0, 1, NULL, NULL),
(191, 2, 1, 6, 17, NULL, NULL, '000-002-01-00000115', '2026-03-15 21:35:00', 'contado', 0, '', '', '', 5993.79, 0.00, 0.00, 5993.79, 0.00, 5993.79, 899.07, 0.00, 6892.86, 'Seis mil ochocientos noventa y dos lempiras con 86/100 centavos', NULL, 'emitida', 0, 1, 1, NULL, NULL),
(192, 2, 1, 6, 17, 12, NULL, '000-002-01-00000116', '2026-03-15 21:39:00', 'contado', 0, '', '', '', 14000.00, 0.00, 0.00, 14000.00, 0.00, 14000.00, 2100.00, 0.00, 16100.00, 'Dieciséis mil ciento lempiras exactos', NULL, 'emitida', 0, 1, 1, 3, 2026),
(193, 2, 1, 6, 17, 16, NULL, '000-002-01-00000117', '2026-03-15 21:39:00', 'contado', 0, '', '', '', 5100.00, 0.00, 0.00, 5100.00, 0.00, 5100.00, 765.00, 0.00, 5865.00, 'Cinco mil ochocientos sesenta y cinco lempiras exactos', NULL, 'emitida', 0, 1, 1, 3, 2026),
(194, 2, 1, 6, 27, 14, NULL, '000-002-01-00000118', '2026-03-25 20:53:00', 'contado', 0, '', '', '', 17391.30, 0.00, 0.00, 17391.30, 0.00, 17391.30, 2608.70, 0.00, 20000.00, 'Veinte mil lempiras exactos', NULL, 'emitida', 0, 0, 1, 3, 2026),
(195, 2, 1, 6, 17, NULL, NULL, '000-002-01-00000119', '2026-04-01 10:11:00', 'contado', 0, '', '', '', 6010.79, 0.00, 0.00, 6010.79, 0.00, 6010.79, 901.62, 0.00, 6912.41, 'Seis mil novecientos doce lempiras con 41/100 centavos', NULL, 'emitida', 0, 0, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `factura_items`
--

CREATE TABLE `factura_items` (
  `id` int(11) NOT NULL,
  `factura_id` int(11) DEFAULT NULL,
  `producto_id` int(11) DEFAULT NULL,
  `descripcion_html` text DEFAULT NULL,
  `cantidad` int(11) DEFAULT NULL,
  `precio_unitario` decimal(10,2) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT NULL,
  `isv_aplicado` decimal(10,2) DEFAULT NULL,
  `isv_15` decimal(10,2) DEFAULT 0.00,
  `isv_18` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `factura_items`
--

INSERT INTO `factura_items` (`id`, `factura_id`, `producto_id`, `descripcion_html`, `cantidad`, `precio_unitario`, `subtotal`, `isv_aplicado`, `isv_15`, `isv_18`) VALUES
(1, 1, 1, 'MES DE DICIEMBRE', 1, 3000.00, 3000.00, 15.00, 0.00, 0.00),
(2, 1, 1, 'MES DE ENERO', 1, 3000.00, 3000.00, 15.00, 0.00, 0.00),
(3, 1, 26, 'UPFM VS REAL ESTELÍ', 4, 75.00, 300.00, 15.00, 0.00, 0.00),
(4, 1, 24, 'VALLA AGAFAM', 1, 300.00, 300.00, 15.00, 0.00, 0.00),
(5, 1, 24, 'VALLA UPNFM', 2, 300.00, 300.00, 15.00, 0.00, 0.00),
(6, 1, 24, 'VALLA JUTICALPA', 2, 300.00, 300.00, 15.00, 0.00, 0.00),
(7, 1, 24, 'AGAFAM 2025', 4, 300.00, 300.00, 15.00, 0.00, 0.00),
(8, 1, 24, 'TRIO DE LOS PANCHOS Y LOS DANDYS', 4, 300.00, 300.00, 15.00, 0.00, 0.00),
(9, 1, 24, 'DARK FUNERAL', 4, 300.00, 300.00, 15.00, 0.00, 0.00),
(10, 1, 24, 'OLANCHO VS MOTAGUA', 4, 300.00, 300.00, 15.00, 0.00, 0.00),
(11, 1, 24, 'GENESIS VS UPNFM', 4, 300.00, 300.00, 15.00, 0.00, 0.00),
(12, 1, 25, 'AGAFAM - INDIE', 1, 300.00, 300.00, 15.00, 0.00, 0.00),
(13, 2, 27, NULL, 1, 16966.91, 16966.91, 15.00, 0.00, 0.00),
(14, 3, 28, 'Texaco Valeriano', 1, 2000.00, 2000.00, 15.00, 0.00, 0.00),
(140, 32, 30, NULL, 1, 3000.00, 3000.00, 15.00, 450.00, 0.00),
(144, 69, 33, '15 de junio al 15 de julio, 2025', 1, 3500.00, 3500.00, 15.00, 0.00, 0.00),
(145, 69, 3, 'Evento Mora, 4 localidades.', 420, 2.50, 1050.00, 15.00, 0.00, 0.00),
(146, 69, 34, '15 de junio al 15 de julio, 2025', 1, 7000.00, 7000.00, 15.00, 0.00, 0.00),
(147, 70, 35, 'MES DE JUNIO, 2025', 1, 9000.00, 9000.00, 15.00, 0.00, 0.00),
(148, 52, 31, 'MES DE MARZO, 2024', 1, 7500.00, 7500.00, 15.00, 1125.00, 0.00),
(149, 66, 32, 'MES DE MAYO, 2025', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(150, 53, 31, 'MES DE ABRIL, 2024', 1, 7500.00, 7500.00, 15.00, 1125.00, 0.00),
(151, 54, 31, 'MES DE MAYO, 2024', 1, 7500.00, 7500.00, 15.00, 1125.00, 0.00),
(152, 55, 32, 'MES DE JUNIO, 2024', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(153, 56, 32, 'MES DE JULIO, 2024', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(154, 57, 32, 'MES DE AGOSTO, 2024', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(155, 58, 32, 'MES DE SEPTIEMBRE, 2024', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(156, 59, 32, 'MES DE OCTUBRE, 2024', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(157, 60, 32, 'MES DE NOVIEMBRE, 2024', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(158, 61, 32, 'MES DE DICIEMBRE, 2024', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(159, 62, 32, 'MES DE ENERO, 2025', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(160, 63, 32, 'MES DE FEBRERO, 2025', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(161, 64, 32, 'MES DE MARZO, 2025', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(162, 65, 32, 'MES DE ABRIL, 2025', 1, 11500.00, 11500.00, 15.00, 1725.00, 0.00),
(163, 12, 35, 'MES DE MAYO, 2025', 1, 9000.00, 9000.00, 15.00, 0.00, 0.00),
(164, 13, 36, 'MES DE ABRIL. 2025', 1, 15500.00, 15500.00, 15.00, 0.00, 0.00),
(166, 14, 3, NULL, 680, 2.50, 1700.00, 15.00, 255.00, 0.00),
(167, 14, 37, NULL, 4202, 2.00, 8404.00, 15.00, 1260.60, 0.00),
(168, 14, 38, NULL, 1900, 0.50, 950.00, 15.00, 142.50, 0.00),
(169, 14, 39, NULL, 1, 3000.00, 3000.00, 15.00, 450.00, 0.00),
(170, 14, 24, NULL, 4, 100.00, 400.00, 15.00, 60.00, 0.00),
(171, 71, 36, 'MES DE MAYO, 2025', 1, 15500.00, 15500.00, 15.00, 0.00, 0.00),
(172, 72, 40, 'PARTIDO JORNADA 1 / GÉNESIS VS MOTAGUA', 7500, 3.00, 22500.00, 15.00, 0.00, 0.00),
(212, 75, 44, 'JORNADA 1 - GÉNESIS FC VS MOTAGUA', 5, 600.00, 3000.00, 15.00, 0.00, 0.00),
(213, 28, 29, '', 1, 28676.67, 28676.67, 15.00, 4301.50, 0.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `factura_items_receptor`
--

CREATE TABLE `factura_items_receptor` (
  `id` int(11) NOT NULL,
  `factura_id` int(11) DEFAULT NULL,
  `producto_id` int(11) DEFAULT NULL,
  `descripcion_html` text DEFAULT NULL,
  `cantidad` int(11) DEFAULT NULL,
  `precio_unitario` decimal(12,3) NOT NULL DEFAULT 0.000,
  `subtotal` decimal(10,2) DEFAULT NULL,
  `isv_aplicado` decimal(10,2) DEFAULT NULL,
  `isv_15` decimal(10,2) DEFAULT 0.00,
  `isv_18` decimal(10,2) DEFAULT 0.00,
  `comision_activa` tinyint(1) NOT NULL DEFAULT 0,
  `comision_unitaria` decimal(12,3) NOT NULL DEFAULT 0.000,
  `comision_porcentaje` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `factura_items_receptor`
--

INSERT INTO `factura_items_receptor` (`id`, `factura_id`, `producto_id`, `descripcion_html`, `cantidad`, `precio_unitario`, `subtotal`, `isv_aplicado`, `isv_15`, `isv_18`, `comision_activa`, `comision_unitaria`, `comision_porcentaje`) VALUES
(1, 1, 1, 'MES DE DICIEMBRE', 1, 3000.000, 3000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(2, 1, 1, 'MES DE ENERO', 1, 3000.000, 3000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(3, 1, 26, 'UPFM VS REAL ESTELÍ', 4, 75.000, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(4, 1, 24, 'VALLA AGAFAM', 1, 300.000, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(5, 1, 24, 'VALLA UPNFM', 2, 300.000, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(6, 1, 24, 'VALLA JUTICALPA', 2, 300.000, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(7, 1, 24, 'AGAFAM 2025', 4, 300.000, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(8, 1, 24, 'TRIO DE LOS PANCHOS Y LOS DANDYS', 4, 300.000, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(9, 1, 24, 'DARK FUNERAL', 4, 300.000, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(10, 1, 24, 'OLANCHO VS MOTAGUA', 4, 300.000, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(11, 1, 24, 'GENESIS VS UPNFM', 4, 300.000, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(12, 1, 25, 'AGAFAM - INDIE', 1, 300.000, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(13, 2, 27, NULL, 1, 16966.910, 16966.91, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(14, 3, 28, 'Texaco Valeriano', 1, 2000.000, 2000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(140, 32, 30, NULL, 1, 3000.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(147, 70, 37, 'MES DE JUNIO, 2025', 1, 9000.000, 9000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(148, 52, 31, 'MES DE MARZO, 2024', 1, 7500.000, 7500.00, 15.00, 1125.00, 0.00, 0, 0.000, 0.00),
(149, 66, 32, 'MES DE MAYO, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(150, 53, 31, 'MES DE ABRIL, 2024', 1, 7500.000, 7500.00, 15.00, 1125.00, 0.00, 0, 0.000, 0.00),
(151, 54, 31, 'MES DE MAYO, 2024', 1, 7500.000, 7500.00, 15.00, 1125.00, 0.00, 0, 0.000, 0.00),
(152, 55, 32, 'MES DE JUNIO, 2024', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(153, 56, 32, 'MES DE JULIO, 2024', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(154, 57, 32, 'MES DE AGOSTO, 2024', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(155, 58, 32, 'MES DE SEPTIEMBRE, 2024', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(156, 59, 32, 'MES DE OCTUBRE, 2024', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(157, 60, 32, 'MES DE NOVIEMBRE, 2024', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(158, 61, 32, 'MES DE DICIEMBRE, 2024', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(159, 62, 32, 'MES DE ENERO, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(160, 63, 32, 'MES DE FEBRERO, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(161, 64, 32, 'MES DE MARZO, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(162, 65, 32, 'MES DE ABRIL, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(163, 12, 37, 'MES DE MAYO, 2025', 1, 9000.000, 9000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(164, 13, 48, 'MES DE ABRIL. 2025', 1, 15500.000, 15500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(166, 14, 3, NULL, 680, 2.500, 1700.00, 15.00, 255.00, 0.00, 0, 0.000, 0.00),
(167, 14, 38, NULL, 4202, 2.000, 8404.00, 15.00, 1260.60, 0.00, 0, 0.000, 0.00),
(168, 14, 39, NULL, 1900, 0.500, 950.00, 15.00, 142.50, 0.00, 0, 0.000, 0.00),
(169, 14, 41, NULL, 1, 3000.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(170, 14, 40, NULL, 4, 100.000, 400.00, 15.00, 60.00, 0.00, 0, 0.000, 0.00),
(171, 71, 48, 'MES DE MAYO, 2025', 1, 15500.000, 15500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(172, 72, 47, 'PARTIDO JORNADA 1 / GÉNESIS VS MOTAGUA', 7500, 3.000, 22500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(260, 90, 52, 'GWM', 1, 20300.000, 20300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(261, 90, 53, 'FICHAS TÉCNICAS DE VEHÍCULOS PARA SITIO WEB FORMATO PDF EDITABLE', 1, 850.000, 850.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(262, 91, 54, '', 1, 197000.000, 197000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(263, 92, 55, '', 1, 7000.000, 7000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(264, 92, 56, '', 1, 7000.000, 7000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(265, 93, 57, '', 1, 5993.790, 5993.79, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(266, 94, 57, '', 1, 5993.790, 5993.79, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(267, 95, 31, 'MES DE ENERO, 2024', 1, 7500.000, 7500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(268, 96, 31, 'MES DE FEBRERO, 2024', 1, 7500.000, 7500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(269, 97, 31, 'MES DE MARZO, 2024', 1, 7500.000, 7500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(270, 98, 31, '', 1, 7500.000, 7500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(271, 99, 53, '', 9, 850.000, 7650.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(272, 100, 53, 'FICHAS TÉCNICAS DE VEHÍCULOS PARA SITIO WEB FORMATO PDF EDITABLE', 9, 850.000, 7650.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(274, 102, 52, '', 1, 20300.000, 20300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(275, 103, 52, 'SITIO WEB DE CHANGAN.HN', 1, 23500.000, 23500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(276, 104, 58, '', 1, 24750.000, 24750.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(277, 105, 59, 'CONTRA ENTREGA A SATISFACCIÓN DE PÁGINA WEB Y MANUL DE USO SITIO WEB CARE HONDURAS', 1, 36750.000, 36750.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(278, 106, 60, 'PAGO DEL 50% DEL DESARROLLO DEL SITIO WEB VALERIANO HONDURAS', 1, 8800.000, 8800.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(279, 107, 3, 'EVENTO MISS UNIVERSO HN', 120, 2.500, 300.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(280, 107, 3, 'HIJOS DE MORAZÁN', 450, 2.500, 1125.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(281, 107, 3, 'EVENTO BLUEY Y BINGO LIVE SHOW', 690, 2.500, 1725.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(282, 107, 1, 'MES DE AGOSTO, 2024', 1, 1500.000, 1500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(283, 107, 62, '', 1, 6500.000, 6500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(284, 107, 38, 'EVENTO GATORADE', 654, 0.000, 0.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(285, 107, 61, 'ACTUALIZACIÓN, REDISEÑO, LICENCIAS Y SEGURIDAD WEB', 1, 15000.000, 15000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(286, 108, 48, '', 1, 16966.910, 16966.91, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(287, 109, 48, 'MES DE SEPTIEMBRE, 2024', 1, 16966.910, 16966.91, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(288, 110, 63, '', 1, 4853.900, 4853.90, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(289, 111, 48, '', 1, 16966.910, 16966.91, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(292, 112, 3, 'EVENTO DE YOUNG MIKO', 490, 2.500, 1225.00, 15.00, 183.75, 0.00, 0, 0.000, 0.00),
(293, 112, 1, 'MES DE SEPTIEMBRE, 2024', 1, 1500.000, 1500.00, 15.00, 225.00, 0.00, 0, 0.000, 0.00),
(294, 113, 48, 'MES DE OCTUBRE, 2024', 1, 16966.910, 16966.91, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(295, 114, 3, '', 810, 2.500, 2025.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(296, 115, 3, 'MORAT', 810, 2.500, 2025.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(297, 115, 3, 'AVENTURA', 1280, 2.500, 3200.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(298, 115, 3, 'KUDAI', 590, 2.500, 1475.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(299, 115, 1, '', 1, 3000.000, 3000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(300, 115, 64, 'APP BMT', 1, 2484.900, 2484.90, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(301, 115, 65, 'APP BMT', 1, 627.500, 627.50, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(302, 115, 26, 'LA NOCHE DEL RECUERDO, THE FEMME EXPERIENCE, EL LAGO DE LOS CISNES, RAGNAROK - WATAIN, BOMBAU WEEN', 1, 1500.000, 1500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(303, 116, 66, 'PAGO DEL 50% DE ADELANTO POR DESARROLLO SITIO WEB, POSICIONAMIENTO SEO Y HERRAMIENTAS DE GOOGLE', 1, 11500.000, 11500.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(304, 117, 27, 'MES DE NOVIEMBRE, 2024', 1, 16966.910, 16966.91, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(305, 118, 27, 'MES DE DICIEMBRE, 2024', 1, 16966.910, 16966.91, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(306, 119, 67, 'BOLETOS DE CONCIERTO DE AVENTURA PARA GIVEAWAY', 1, 2600.000, 2600.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(307, 120, 3, 'EVENTO REIK', 610, 2.500, 1525.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(308, 120, 3, 'EVENTO THE MENTORS', 550, 2.500, 1375.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(309, 120, 3, 'EVENTO FRESH HONDURAS', 330, 2.500, 825.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(310, 120, 1, 'MES DE NOVIEMBRE', 1, 3000.000, 3000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(311, 120, 26, 'TIERRA SANTA, FABRICE PASTOR CUP, BOLETO LACUNA COIL, OLANCHO VS OLIMPIA, REAL ESPAÑA VS MOTAGUA', 1, 1275.000, 1275.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(312, 121, 4, '', 1, 0.000, 0.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(313, 122, 27, 'MES DE NOVIEMBRE', 1, 16966.910, 16966.91, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(314, 123, 27, 'MES DE DICIEMBRE, 2024', 1, 16966.910, 16966.91, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(315, 124, 67, 'BOLETOS DE CONCIERTO DE AVENTURA PARA GIVEAWAY', 1, 2600.000, 2600.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(316, 101, 58, 'CONTENIDO E IMAGENES DE 383 ENTRADAS Y ARTÍCULOS DEL SITIO WEB CARE HONDURAS', 1, 24750.000, 24750.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(320, 69, 35, '15 de junio al 15 de julio, 2025', 1, 3500.000, 3500.00, 15.00, 525.00, 0.00, 0, 0.000, 0.00),
(321, 69, 3, 'Evento Mora, 4 localidades.', 420, 2.500, 1050.00, 15.00, 157.50, 0.00, 0, 0.000, 0.00),
(322, 69, 36, '15 de junio al 15 de julio, 2025', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(370, 28, 29, '', 1, 28676.670, 28676.67, 15.00, 4301.50, 0.00, 0, 0.000, 0.00),
(436, 75, 45, 'JORNADA 1 - GÉNESIS FC VS MOTAGUA', 5, 600.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(437, 79, 69, 'MES DE JUNIO', 1, 15500.000, 15500.00, 15.00, 2325.00, 0.00, 0, 0.000, 0.00),
(438, 80, 49, 'REEL DE FACEBOOK PROMOCIONADO, 13 DE ABRIL AL 21 DE ABRIL, 2025', 1, 1497.250, 1497.25, 15.00, 224.59, 0.00, 0, 0.000, 0.00),
(439, 80, 49, 'REEL DE INSTAGRAM PROMOCIONADO, 20 DE ABRIL AL 5 DE MAYO, 2025', 1, 1581.600, 1581.60, 15.00, 237.24, 0.00, 0, 0.000, 0.00),
(440, 80, 49, 'PUBLICACIÓN DE FACEBOOK PROMOCIONADA - VIDEO CORPORATIVO, 20 DE ABRIL AL 10 DE MAYO, 2025', 1, 599.880, 599.88, 15.00, 89.98, 0.00, 0, 0.000, 0.00),
(441, 80, 49, 'PUBLICACIÓN DE INSTAGRAM PROMOCIONADA - VIDEO CORPORATIVO, 20 DE ABRIL AL 10 DE MAYO, 2025', 1, 577.620, 577.62, 15.00, 86.64, 0.00, 0, 0.000, 0.00),
(442, 81, 37, 'MES DE JULIO, 2025', 1, 9000.000, 9000.00, 15.00, 1350.00, 0.00, 0, 0.000, 0.00),
(443, 82, 3, 'EVENTO NODAL', 665, 2.500, 1662.50, 15.00, 249.38, 0.00, 0, 0.000, 0.00),
(444, 82, 1, 'MES DE JULIO, 2025', 1, 3000.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(445, 82, 36, 'MES DE JULIO, 2025', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(446, 82, 43, 'EVENTO BETO CUEVAS', 1, 0.000, 0.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(447, 82, 42, 'ESPIRIA TRAVEL', 2, 400.000, 800.00, 15.00, 120.00, 0.00, 0, 0.000, 0.00),
(448, 82, 3, '', 890, 2.500, 2225.00, 15.00, 333.75, 0.00, 0, 0.000, 0.00),
(449, 82, 43, '', 1, 0.000, 0.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(450, 128, 71, 'DIAGNÓSTICO, MANTENIMIENTO Y RECONFIGURACIÓN DE RED PARA EL EQUIPO DEL DR. MUNGUÍA (SERVIDOR DE RECEPCIÓN).\r\nSE REALIZARON LAS SIGUIENTES LABORES:\r\n•REVISIÓN Y SUSTITUCIÓN DE CONEXIÓN DEFECTUOSA ENTRE EQUIPO Y SWITCH DE RED.\r\n•LIMPIEZA Y CAMBIO DE PUERTO EN EL SWITCH PARA RESTABLECER LA CONECTIVIDAD FÍSICA.\r\n•ACTUALIZACIÓN Y RECONFIGURACIÓN DE DIRECCIONES IP Y PARÁMETROS DE RED DEBIDO AL CAMBIO DE PUERTO.\r\n•CONFIGURACIÓN DE FIREWALL Y HABILITACIÓN DE PUERTOS PARA GARANTIZAR EL ACCESO A LOS SERVICIOS DEL SERVIDOR.\r\n•VERIFICACIÓN DE ACCESIBILIDAD A APLICACIONES LOCALES Y DEL SISTEMA EN ENTORNO WAMP.\r\n•PRUEBAS DE CONECTIVIDAD Y FUNCIONALIDAD DEL SISTEMA EN RECEPCIÓN.', 1, 2043.480, 2043.48, 15.00, 306.52, 0.00, 0, 0.000, 0.00),
(451, 129, 47, 'JORNADA 5 - GÉNESIS FC VS OLANCHO FC', 6000, 3.000, 18000.00, 15.00, 2700.00, 0.00, 0, 0.000, 0.00),
(452, 130, 71, 'Optimización y Normalización de la Red Interna de la Clínica.\r\nSe realizó la revisión y reorganización de la red de la clínica para que todas las computadoras y equipos puedan comunicarse correctamente entre sí.\r\nSe habilitaron computadoras en el área de Imágenes y se conectaron los equipos de Administración con la misma.\r\nSe configuraron los switches y routers para mejorar la conexión y evitar problemas de comunicación entre departamentos.\r\nTambién se corrigieron problemas que impedían ver o acceder a otros equipos en la red, asegurando el correcto uso de los programas y recursos compartidos.', 1, 8173.910, 8173.91, 15.00, 1226.09, 0.00, 0, 0.000, 0.00),
(453, 131, 45, 'JORNADA 5 GENESIS FC VS OLANCHO FC', 5, 600.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(454, 133, 72, 'PARTIDO JORNADA 7 - GÉNESIS FC VS VICTORIA', 4500, 3.000, 13500.00, 15.00, 2025.00, 0.00, 0, 0.000, 0.00),
(455, 133, 73, 'JORNADA 7 - GÉNESIS FC VS VICTORIA', 5, 600.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(561, 135, 74, 'EVENTO CIRCUITO CASTOR, 2025 - CORTESÍA', 1490, 1.000, 1490.00, 15.00, 223.50, 0.00, 0, 0.000, 0.00),
(562, 135, 1, 'MES DE AGOSTO A SEPTIEMBRE, 2025', 1, 3000.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(563, 135, 36, 'MES DE AGOSTO A SEPTIEMBRE, 2025', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(564, 135, 44, 'EVENTO CIRCUITO CASTOR, 2025', 1750, 1.700, 2975.00, 15.00, 446.25, 0.00, 0, 0.000, 0.00),
(565, 135, 3, 'EVENTO DANNY OCEAN', 835, 2.500, 2087.50, 15.00, 313.13, 0.00, 0, 0.000, 0.00),
(566, 135, 3, 'EVENTO BMW', 10, 2.500, 25.00, 15.00, 3.75, 0.00, 0, 0.000, 0.00),
(567, 135, 3, 'EVENTO FIESTAS CIVICAS 2025', 5600, 2.500, 14000.00, 15.00, 2100.00, 0.00, 0, 0.000, 0.00),
(568, 135, 24, 'BOLETO DORADO BMT', 1, 150.000, 150.00, 15.00, 22.50, 0.00, 0, 0.000, 0.00),
(569, 135, 70, 'CORTESÍA EVENTO CIRCUITO CASTOR, 2025', 1, 0.000, 0.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(570, 136, 32, 'Mes de Junio, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(571, 137, 32, 'Mes de Julio, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(572, 138, 72, 'JORNADA 9 GENESIS VS REAL ESPAÑA', 4500, 3.000, 13500.00, 15.00, 2025.00, 0.00, 0, 0.000, 0.00),
(573, 138, 45, 'JORNADA 9 GENESIS VS REAL ESPAÑA', 5, 600.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(574, 138, 47, 'JORNADA 10 GENESIS VS PLATENSE', 4500, 3.000, 13500.00, 15.00, 2025.00, 0.00, 0, 0.000, 0.00),
(575, 139, 37, 'Mes de Agosto', 1, 9000.000, 9000.00, 15.00, 1350.00, 0.00, 0, 0.000, 0.00),
(576, 140, 75, 'MES DE JULIO, 2025', 1, 15500.000, 15500.00, 15.00, 2325.00, 0.00, 0, 0.000, 0.00),
(699, 141, 47, 'JORNADA 12 - GÉNESIS FC VS UPNFM', 4500, 3.000, 13500.00, 15.00, 2025.00, 0.00, 0, 0.000, 0.00),
(700, 141, 45, '', 5, 600.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(703, 142, 1, 'BANDAS PARA EL CONCIERTO DE JUANES', 1, 3000.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(704, 142, 36, 'MES DE SEPTIEMBRE - OCTUBRE 2025', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(705, 142, 3, 'EVENTO COKE STUDIO MUSIC FEST', 1040, 2.500, 2600.00, 15.00, 390.00, 0.00, 0, 0.000, 0.00),
(706, 142, 38, '', 1908, 0.500, 954.00, 15.00, 143.10, 0.00, 0, 0.000, 0.00),
(707, 142, 3, '', 770, 2.500, 1925.00, 15.00, 288.75, 0.00, 0, 0.000, 0.00),
(708, 143, 37, 'Mes de Septiembre, 2025', 1, 9000.000, 9000.00, 15.00, 1350.00, 0.00, 0, 0.000, 0.00),
(709, 144, 75, 'Promoción del partido Honduras vs Costa Rica, el día jueves, 9 de octubre, 2025', 1, 15500.000, 15500.00, 15.00, 2325.00, 0.00, 0, 0.000, 0.00),
(710, 144, 76, 'Hosting Web Hosting Deluxe, renovación anual valerianohonduras.com', 1, 3923.000, 3923.00, 15.00, 588.45, 0.00, 0, 0.000, 0.00),
(711, 144, 77, '', 1, 326.940, 326.94, 15.00, 49.04, 0.00, 0, 0.000, 0.00),
(712, 145, 47, 'JORNADA 15 - GÉNESIS VS MARATHON', 2350, 3.000, 7050.00, 15.00, 1057.50, 0.00, 0, 0.000, 0.00),
(713, 146, 32, 'Mes de Agosto, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(714, 147, 32, 'Mes de Septiembre, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(715, 148, 34, 'Campaña 1  - Mes de Mayo, 2025', 1, 2487.090, 2487.09, 15.00, 373.06, 0.00, 0, 0.000, 0.00),
(716, 148, 34, 'Campaña 2 - Mes de Junio, 2025', 1, 1197.490, 1197.49, 15.00, 179.62, 0.00, 0, 0.000, 0.00),
(717, 148, 34, 'Campaña 3 - Mes de Agosto, 2025', 1, 787.740, 787.74, 15.00, 118.16, 0.00, 0, 0.000, 0.00),
(718, 148, 34, 'Campaña 4  - Mes de Septiembre, 2025', 1, 145.270, 145.27, 15.00, 21.79, 0.00, 0, 0.000, 0.00),
(719, 148, 34, 'Campaña 5  - Mes de Septiembre, 2025', 1, 1397.820, 1397.82, 15.00, 209.67, 0.00, 0, 0.000, 0.00),
(753, 149, 47, 'JORNADA 17 - GÉNESIS VS CHOLOMA', 2000, 3.000, 6000.00, 15.00, 900.00, 0.00, 0, 0.000, 0.00),
(754, 150, 1, 'EVENTO MELENDI', 1, 3000.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(755, 150, 36, 'Mes de Octubre 2025', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(756, 150, 3, 'Concierto Omar Courtz', 650, 2.500, 1625.00, 15.00, 243.75, 0.00, 0, 0.000, 0.00),
(757, 150, 44, 'BLUE RUN, NOVIEMBRE 2025', 90, 1.700, 153.00, 15.00, 22.95, 0.00, 0, 0.000, 0.00),
(758, 150, 62, 'BLUE RUN, NOVIEMBRE 2025', 1, 1083.330, 1083.33, 15.00, 162.50, 0.00, 0, 0.000, 0.00),
(759, 150, 3, 'CONCIERTO LATIN MAFIA', 670, 2.500, 1675.00, 15.00, 251.25, 0.00, 0, 0.000, 0.00),
(760, 150, 3, 'CONCIERTO NATANAEL CANO', 820, 2.500, 2050.00, 15.00, 307.50, 0.00, 0, 0.000, 0.00),
(761, 150, 3, 'CONCIERTO MELENDI', 700, 2.500, 1750.00, 15.00, 262.50, 0.00, 0, 0.000, 0.00),
(762, 150, 74, '', 1, 300.000, 300.00, 15.00, 45.00, 0.00, 0, 0.000, 0.00),
(763, 151, 47, 'JORNADA 19 - GÉNESIS VS CD OLIMPIA', 9200, 3.000, 27600.00, 15.00, 4140.00, 0.00, 0, 0.000, 0.00),
(764, 152, 37, 'MES OCTUBRE, 2025', 1, 9000.000, 9000.00, 15.00, 1350.00, 0.00, 0, 0.000, 0.00),
(765, 153, 48, 'Mes de Septiembre, 2025', 1, 15500.000, 15500.00, 15.00, 2325.00, 0.00, 0, 0.000, 0.00),
(766, 153, 50, 'Campaña de seguidores, Mes de Septiembre, 2025', 1, 1200.000, 1200.00, 15.00, 180.00, 0.00, 0, 0.000, 0.00),
(767, 156, 32, 'MES DE OCTUBRE, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(768, 157, 32, 'MES DE NOVIEMBRE, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(807, 161, 48, 'MES DE OCTUBRE, 2025', 1, 15500.000, 15500.00, 15.00, 2325.00, 0.00, 0, 0.000, 0.00),
(808, 158, 1, 'MES DE NOVIEMBRE, 2025', 1, 3000.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(809, 158, 36, 'MES DE NOVIEMBRE, 2025', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(810, 158, 3, 'EVENTO SEBASTIAN YATRA, 2025', 260, 2.500, 650.00, 15.00, 97.50, 0.00, 0, 0.000, 0.00),
(811, 158, 3, 'EVENTO BRONCO, 2025', 860, 2.500, 2150.00, 15.00, 322.50, 0.00, 0, 0.000, 0.00),
(812, 159, 47, 'JORNADA 21 - GÉNESIS VS JUTICALPA', 2500, 3.000, 7500.00, 15.00, 1125.00, 0.00, 0, 0.000, 0.00),
(813, 160, 37, 'MES DE NOVIEMBRE, 2025', 1, 9000.000, 9000.00, 15.00, 1350.00, 0.00, 0, 0.000, 0.00),
(816, 163, 32, 'MES DE DICIEMBRE, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(853, 172, 36, 'MES DE ENERO A FEBRERO, 2026 - EVENTO KPOP', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(854, 172, 80, 'MES DE ENERO A FEBRERO, 2026 - EVENTO KPOP', 1, 10000.000, 10000.00, 15.00, 1500.00, 0.00, 0, 0.000, 0.00),
(855, 172, 81, 'MES DE ENERO A FEBRERO, 2026 - EVENTO KPOP', 1, 2000.000, 2000.00, 15.00, 300.00, 0.00, 0, 0.000, 0.00),
(882, 162, 78, 'Incluyendo UX/UI, estructura y carga de contenido, catálogo de marcas, blog/noticias, formularios de generación de leads (proveedores y distribuidores), panel de administración, optimización SEO e integración de analítica, conforme Contrato de Prestación de Servicios de fecha 18/03/2025. Monto: US$1,265.00 (equivalente a L 33,359.19 al tipo de cambio Compra L 26.3709 por US$1, aplicado el 22/12/2025).', 1, 29007.990, 29007.99, 15.00, 4351.20, 0.00, 0, 0.000, 0.00),
(883, 165, 1, 'MES DE DICIEMBRE, 2025', 1, 3000.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(884, 165, 36, 'MES DE DICIEMBRE, 2025', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(885, 166, 32, 'MES DE ENERO, 2025', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(886, 166, 34, 'MES DE 21 NOVIEMBRE A ENERO 21, 2025', 1, 7497.100, 7497.10, 15.00, 1124.57, 0.00, 0, 0.000, 0.00),
(887, 166, 82, 'MES DE ENERO, 2025', 1, 3550.000, 3550.00, 15.00, 532.50, 0.00, 0, 0.000, 0.00),
(888, 173, 83, 'CRM Inmobiliario.', 1, 162000.000, 162000.00, 15.00, 24300.00, 0.00, 0, 0.000, 0.00),
(889, 168, 37, 'MES DE DICIEMBRE, 2025', 1, 9000.000, 9000.00, 15.00, 1350.00, 0.00, 0, 0.000, 0.00),
(892, 164, 79, 'ANUALIDAD, ENERO 2026', 1, 10000.000, 10000.00, 15.00, 1500.00, 0.00, 0, 0.000, 0.00),
(968, 187, 41, '', 1, 3000.000, 3000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(969, 187, 36, '', 1, 7000.000, 7000.00, 15.00, 0.00, 0.00, 0, 0.000, 0.00),
(971, 183, 82, '', 1, 1578.000, 1578.00, 15.00, 236.70, 0.00, 0, 0.000, 0.00),
(972, 183, 34, '', 1, 3821.180, 3821.18, 15.00, 573.18, 0.00, 0, 0.000, 0.00),
(973, 182, 32, '', 1, 11500.000, 11500.00, 15.00, 1725.00, 0.00, 0, 0.000, 0.00),
(974, 179, 90, 'MES DE FEBRERO, 2026', 1, 25000.000, 25000.00, 15.00, 3750.00, 0.00, 0, 0.000, 0.00),
(975, 178, 87, 'Desarrollo de un sitio web funcional, moderno y optimizado, alineado con los objetivos instucionales de\r\nimpacto, transparencia y escalabilidad.', 1, 23000.000, 23000.00, 15.00, 3450.00, 0.00, 0, 0.000, 0.00),
(976, 178, 88, 'desarrollo de una plataforma web personalizada para la\r\ngestión de préstamos de PROGRESE. El sistema permitirá a los usuarios acceder a su\r\ninformación financiera, consultar cuotas, pagos y solicitar nuevos préstamos, todo\r\ndesde una interfaz moderna, segura y amigable.', 1, 115500.000, 115500.00, 15.00, 17325.00, 0.00, 0, 0.000, 0.00),
(977, 178, 89, 'Este módulo contempla la conexión con la pasarela de pagos de Banco Atlántida,\r\nbajo las mejores prácticas de seguridad y pruebas técnicas exigidas para la gestión de\r\npagos en línea.', 1, 50000.000, 50000.00, 15.00, 7500.00, 0.00, 0, 0.000, 0.00),
(978, 177, 37, 'MES DE ENERO, 2026', 1, 9000.000, 9000.00, 15.00, 1350.00, 0.00, 0, 0.000, 0.00),
(981, 180, 91, 'Actualización de dirección dentro del sitio web, Actualización de fotografía de fachada en el sitio web, Actualización de dirección en Google My Business (Google Business Profile)', 1, 2800.000, 2800.00, 15.00, 420.00, 0.00, 0, 0.000, 0.00),
(982, 176, 75, 'MES DE DICIEMBRE, 2025', 1, 15500.000, 15500.00, 15.00, 2325.00, 0.00, 0, 0.000, 0.00),
(983, 176, 77, 'MES DE ENERO, 2026 - PUBLICACIÓN DE CAJERO', 1, 500.000, 500.00, 15.00, 75.00, 0.00, 0, 0.000, 0.00),
(984, 175, 36, 'MES DE ENERO A FEBRERO, 2026', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(985, 175, 41, 'MES DE ENERO A FEBRERO, 2026', 1, 3000.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(986, 175, 36, 'KPOP - MES DE ENERO A FEBRERO, 2026', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(987, 175, 80, 'KPOP - MES DE ENERO A FEBRERO, 2026', 1, 10000.000, 10000.00, 15.00, 1500.00, 0.00, 0, 0.000, 0.00),
(988, 175, 85, 'CONSUMO EVENTO DE GOOGLE CLOUD', 41050, 0.090, 3694.50, 15.00, 554.18, 0.00, 0, 0.000, 0.00),
(989, 175, 86, 'MES DE ENERO Y FEBRERO, 2026', 2, 800.000, 1600.00, 15.00, 240.00, 0.00, 0, 0.000, 0.00),
(990, 175, 39, 'AGAFAM - SOPORTE, ADMINISTRACIÓN, IMPRESIÓN Y ESCANEO', 41050, 0.500, 20525.00, 15.00, 3078.75, 0.00, 0, 0.000, 0.00),
(993, 186, 75, 'MES DE ENERO, 2026', 1, 15500.000, 15500.00, 15.00, 2325.00, 0.00, 0, 0.000, 0.00),
(994, 186, 77, 'VACANTE LABORAL', 1, 500.000, 500.00, 15.00, 75.00, 0.00, 0, 0.000, 0.00),
(995, 185, 37, 'MES DE FEBRERO, 2026', 1, 9000.000, 9000.00, 15.00, 1350.00, 0.00, 0, 0.000, 0.00),
(1007, 188, 95, 'IMPRESIÓN DE LOGO', 500, 5.870, 2935.00, 15.00, 440.25, 0.00, 0, 0.000, 0.00),
(1008, 188, 95, 'IMPRESIÓN DE AGUA ES EDUCACIÓN', 334, 5.870, 1960.58, 15.00, 294.09, 0.00, 0, 0.000, 0.00),
(1009, 188, 95, 'IMPRESIÓN AULA EL PROGRESO', 334, 5.870, 1960.58, 15.00, 294.09, 0.00, 0, 0.000, 0.00),
(1010, 188, 95, 'IMPRESIÓN PROTEGE EL AGUA', 334, 5.870, 1960.58, 15.00, 294.09, 0.00, 0, 0.000, 0.00),
(1043, 190, 1, 'MES DE FEBRERO, 2026', 1, 3000.000, 3000.00, 15.00, 450.00, 0.00, 0, 0.000, 0.00),
(1044, 190, 36, 'MES DE FEBRERO, 2026', 1, 7000.000, 7000.00, 15.00, 1050.00, 0.00, 0, 0.000, 0.00),
(1045, 190, 3, 'EVENTO KOP, MARZO 2026', 440, 2.500, 1100.00, 15.00, 165.00, 0.00, 0, 0.000, 0.00),
(1046, 190, 39, 'EVENTO KOP, MARZO 2026', 2000, 0.500, 1000.00, 15.00, 150.00, 0.00, 0, 0.000, 0.00),
(1047, 190, 85, 'EVENTO KOP, MARZO 2026', 12455, 0.090, 1120.95, 15.00, 168.14, 0.00, 0, 0.000, 0.00),
(1048, 190, 86, 'EVENTO KOP, MARZO 2026', 2, 800.000, 1600.00, 15.00, 240.00, 0.00, 0, 0.000, 0.00),
(1049, 190, 3, 'EVENTO JUNIO H, MARZO 2026', 150, 2.500, 375.00, 15.00, 56.25, 0.00, 0, 0.000, 0.00),
(1050, 190, 3, 'EVENTO MATUTE, MARZO 2026', 600, 2.500, 1500.00, 15.00, 225.00, 0.00, 0, 0.000, 0.00),
(1051, 190, 3, 'EVENTO LATINOAMERICA EN LAGRIMAS, MARZO 2026', 150, 2.500, 375.00, 15.00, 56.25, 0.00, 0, 0.000, 0.00),
(1052, 7, 48, 'MES DE MARZO, 2025', 1, 15500.000, 15500.00, 15.00, 2325.00, 0.00, 0, 0.000, 0.00),
(1054, 194, 93, 'MES DE MARZO, 2025', 1, 17391.300, 17391.30, 15.00, 2608.70, 0.00, 0, 0.000, 0.00),
(1055, 193, 94, 'MES DE MARZO, 2026', 1, 4000.000, 4000.00, 15.00, 600.00, 0.00, 0, 0.000, 0.00),
(1056, 193, 82, '', 1, 1100.000, 1100.00, 15.00, 165.00, 0.00, 0, 0.000, 0.00),
(1057, 192, 92, 'MES DE MARZO, 2026', 1, 14000.000, 14000.00, 15.00, 2100.00, 0.00, 0, 0.000, 0.00),
(1058, 191, 57, 'RENOVACIÓN ANUAL, AÑOS 2026 AL 2028', 1, 5993.790, 5993.79, 15.00, 899.07, 0.00, 0, 0.000, 0.00),
(1059, 171, 69, 'PUBLICACIÓN DE OPORTUNIDAD LABORAL - MES DE NOVIEMBRE, 2025', 1, 15500.000, 15500.00, 15.00, 2325.00, 0.00, 0, 0.000, 0.00),
(1060, 171, 49, '', 1, 1420.000, 1420.00, 15.00, 213.00, 0.00, 0, 0.000, 0.00),
(1061, 189, 90, 'MES DE MARZO, 2026', 1, 25000.000, 25000.00, 15.00, 3750.00, 0.00, 0, 0.000, 0.00),
(1063, 195, 57, 'ANUALIDAD DEL HOSTING/ALOJAMIENTO DEL SITIO WEB WWW.SIGURBAN.COM', 1, 6010.790, 6010.79, 15.00, 901.62, 0.00, 0, 0.000, 0.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `gastos`
--

CREATE TABLE `gastos` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `descripcion` varchar(300) NOT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fecha` date NOT NULL,
  `frecuencia` enum('unico','mensual','quincenal','anual') NOT NULL DEFAULT 'unico',
  `dia_pago` tinyint(3) UNSIGNED DEFAULT NULL,
  `dia_pago_2` tinyint(3) UNSIGNED DEFAULT NULL,
  `gasto_grupo_id` int(11) DEFAULT NULL,
  `quincena_num` tinyint(1) DEFAULT NULL,
  `tipo` enum('fijo','variable','extraordinario','viaticos') NOT NULL DEFAULT 'variable',
  `metodo_pago` enum('efectivo','transferencia','cheque','tarjeta','otro') NOT NULL DEFAULT 'efectivo',
  `tarjeta_id` int(10) UNSIGNED DEFAULT NULL,
  `proveedor` varchar(200) DEFAULT NULL,
  `factura_ref` varchar(100) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `archivo_adjunto` varchar(255) DEFAULT NULL,
  `archivo_nombre` varchar(255) DEFAULT NULL,
  `estado` enum('pendiente','pagado','anulado') NOT NULL DEFAULT 'pagado',
  `comprobante_url` varchar(500) DEFAULT NULL COMMENT 'URL del comprobante de pago (imagen o PDF)',
  `usuario_id` int(11) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `gastos`
--

INSERT INTO `gastos` (`id`, `cliente_id`, `categoria_id`, `descripcion`, `monto`, `fecha`, `frecuencia`, `dia_pago`, `dia_pago_2`, `gasto_grupo_id`, `quincena_num`, `tipo`, `metodo_pago`, `tarjeta_id`, `proveedor`, `factura_ref`, `notas`, `fecha_vencimiento`, `archivo_adjunto`, `archivo_nombre`, `estado`, `comprobante_url`, `usuario_id`, `creado_en`, `actualizado_en`) VALUES
(114, 2, 11, 'Combustible', 300.00, '2026-02-24', 'unico', NULL, NULL, NULL, NULL, 'variable', 'tarjeta', NULL, 'Puma 21 de Agosto', '000-001-01-00287446', NULL, NULL, NULL, NULL, 'pagado', NULL, 7, '2026-02-25 14:58:46', '2026-02-25 14:58:46'),
(117, 2, 16, 'Sueldo Carlos Jafeth Padilla — 1ª Quincena', 7000.00, '2026-02-13', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_043905_bfd8dd97.jpg', 'pago_2_20260226_043905_bfd8dd97.jpg', 'pagado', NULL, 7, '2026-02-25 21:39:05', '2026-02-25 21:39:05'),
(118, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2026-01-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_045117_4609c424.jpg', 'pago_1_20260226_045117_4609c424.jpg', 'pagado', NULL, 7, '2026-02-25 21:51:17', '2026-02-25 21:51:17'),
(119, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2026-02-13', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_045229_45907127.jpg', 'pago_1_20260226_045229_45907127.jpg', 'pagado', NULL, 7, '2026-02-25 21:52:29', '2026-02-25 21:52:29'),
(120, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 1ª Quincena', 3500.00, '2026-02-13', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_045502_e3dcc820.jpg', 'pago_3_20260226_045502_e3dcc820.jpg', 'pagado', NULL, 7, '2026-02-25 21:55:02', '2026-02-25 21:55:02'),
(121, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2026-01-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_050651_34d7b474.jpg', 'pago_1_20260226_050651_34d7b474.jpg', 'pagado', NULL, 7, '2026-02-25 22:06:51', '2026-02-25 22:06:51'),
(123, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2025-06-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_055925_b25693fb.jpg', 'pago_1_20260226_055925_b25693fb.jpg', 'pagado', NULL, 7, '2026-02-25 22:59:25', '2026-02-25 22:59:25'),
(124, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 2ª Quincena', 4000.00, '2024-10-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_062253_cd379177.jpg', 'pago_5_20260226_062253_cd379177.jpg', 'pagado', NULL, 7, '2026-02-25 23:22:53', '2026-02-25 23:22:53'),
(125, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 1ª Quincena', 4000.00, '2024-11-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_062857_4dc91feb.jpg', 'pago_5_20260226_062857_4dc91feb.jpg', 'pagado', NULL, 7, '2026-02-25 23:28:57', '2026-02-25 23:28:57'),
(126, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 2ª Quincena', 4000.00, '2024-11-29', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_062918_1cb2ab23.jpg', 'pago_5_20260226_062918_1cb2ab23.jpg', 'pagado', NULL, 7, '2026-02-25 23:29:18', '2026-02-25 23:29:18'),
(127, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 1ª Quincena', 4000.00, '2024-12-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_062930_727920ce.jpg', 'pago_5_20260226_062930_727920ce.jpg', 'pagado', NULL, 7, '2026-02-25 23:29:30', '2026-02-25 23:29:30'),
(128, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 2ª Quincena', 4000.00, '2024-12-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_062940_9ab45e38.jpg', 'pago_5_20260226_062940_9ab45e38.jpg', 'pagado', NULL, 7, '2026-02-25 23:29:40', '2026-02-25 23:29:40'),
(129, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 1ª Quincena', 4000.00, '2025-01-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_062950_516da5f3.jpg', 'pago_5_20260226_062950_516da5f3.jpg', 'pagado', NULL, 7, '2026-02-25 23:29:50', '2026-02-25 23:29:50'),
(130, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 2ª Quincena', 4000.00, '2025-01-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_063015_6271a97c.jpg', 'pago_5_20260226_063015_6271a97c.jpg', 'pagado', NULL, 7, '2026-02-25 23:30:15', '2026-02-25 23:30:15'),
(131, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 2ª Quincena', 4000.00, '2025-02-28', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_063044_b771093b.jpg', 'pago_5_20260226_063044_b771093b.jpg', 'pagado', NULL, 7, '2026-02-25 23:30:44', '2026-02-25 23:30:44'),
(132, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 1ª Quincena', 4000.00, '2025-03-14', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_063059_b8c16e14.jpg', 'pago_5_20260226_063059_b8c16e14.jpg', 'pagado', NULL, 7, '2026-02-25 23:30:59', '2026-02-25 23:30:59'),
(133, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 2ª Quincena', 4000.00, '2025-03-31', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_063117_eca41c7c.jpg', 'pago_5_20260226_063117_eca41c7c.jpg', 'pagado', NULL, 7, '2026-02-25 23:31:17', '2026-02-25 23:31:17'),
(134, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 1ª Quincena', 4000.00, '2025-04-13', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_063132_8b3f8247.jpg', 'pago_5_20260226_063132_8b3f8247.jpg', 'pagado', NULL, 7, '2026-02-25 23:31:32', '2026-02-25 23:31:32'),
(135, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 2ª Quincena', 4000.00, '2025-04-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_063143_995e2d4e.jpg', 'pago_5_20260226_063143_995e2d4e.jpg', 'pagado', NULL, 7, '2026-02-25 23:31:43', '2026-02-25 23:31:43'),
(136, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 1ª Quincena', 4000.00, '2025-05-14', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_063157_3bdecb86.jpg', 'pago_5_20260226_063157_3bdecb86.jpg', 'pagado', NULL, 7, '2026-02-25 23:31:57', '2026-02-25 23:31:57'),
(137, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 2ª Quincena', 4000.00, '2025-06-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_063237_5742f531.jpg', 'pago_5_20260226_063237_5742f531.jpg', 'pagado', NULL, 7, '2026-02-25 23:32:37', '2026-02-25 23:32:37'),
(138, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 1ª Quincena', 4000.00, '2025-07-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_063255_ea815cae.jpg', 'pago_5_20260226_063255_ea815cae.jpg', 'pagado', NULL, 7, '2026-02-25 23:32:55', '2026-02-25 23:32:55'),
(139, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 2ª Quincena', 4000.00, '2025-07-29', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_063544_7d6d6326.jpg', 'pago_3_20260226_063544_7d6d6326.jpg', 'pagado', NULL, 7, '2026-02-25 23:35:44', '2026-02-25 23:36:06'),
(140, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2025-07-29', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_063632_f60e9e58.jpg', 'pago_1_20260226_063632_f60e9e58.jpg', 'pagado', NULL, 7, '2026-02-25 23:36:32', '2026-02-25 23:36:32'),
(141, 2, 16, 'Sueldo Carlos Jafeth Padilla — 2ª Quincena', 6600.00, '2025-07-29', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_063730_e092eada.jpg', 'pago_2_20260226_063730_e092eada.jpg', 'pagado', NULL, 7, '2026-02-25 23:37:30', '2026-02-25 23:37:30'),
(142, 2, 16, 'Sueldo Carlos Jafeth Padilla — 1ª Quincena', 6600.00, '2025-06-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_065137_bcad35b8.jpg', 'pago_2_20260226_065137_bcad35b8.jpg', 'pagado', NULL, 7, '2026-02-25 23:51:37', '2026-02-25 23:51:37'),
(143, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 1ª Quincena', 4000.00, '2025-06-14', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_065155_9ce906dc.jpg', 'pago_5_20260226_065155_9ce906dc.jpg', 'pagado', NULL, 7, '2026-02-25 23:51:55', '2026-02-25 23:51:55'),
(144, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2025-06-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_065221_de1bfc53.jpg', 'pago_1_20260226_065221_de1bfc53.jpg', 'pagado', NULL, 7, '2026-02-25 23:52:21', '2026-02-25 23:52:21'),
(145, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2025-05-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_065259_ebe9f00f.jpg', 'pago_1_20260226_065259_ebe9f00f.jpg', 'pagado', NULL, 7, '2026-02-25 23:52:59', '2026-02-25 23:52:59'),
(146, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 2ª Quincena', 4000.00, '2025-05-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_065330_8f7c13f0.jpg', 'pago_5_20260226_065330_8f7c13f0.jpg', 'pagado', NULL, 7, '2026-02-25 23:53:30', '2026-02-25 23:53:30'),
(147, 2, 16, 'Sueldo Carlos Jafeth Padilla — 2ª Quincena', 6600.00, '2025-06-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_065404_94183908.jpg', 'pago_2_20260226_065404_94183908.jpg', 'pagado', NULL, 7, '2026-02-25 23:54:04', '2026-02-25 23:54:04'),
(148, 2, 16, 'Sueldo Carlos Jafeth Padilla — 1ª Quincena', 6600.00, '2025-08-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_065805_97dce025.jpg', 'pago_2_20260226_065805_97dce025.jpg', 'pagado', NULL, 7, '2026-02-25 23:58:05', '2026-02-25 23:58:05'),
(149, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 1ª Quincena', 3500.00, '2025-08-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_065821_acb19a32.jpg', 'pago_3_20260226_065821_acb19a32.jpg', 'pagado', NULL, 7, '2026-02-25 23:58:21', '2026-02-25 23:58:21'),
(150, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2025-08-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_065831_a7e09ba8.jpg', 'pago_1_20260226_065831_a7e09ba8.jpg', 'pagado', NULL, 7, '2026-02-25 23:58:31', '2026-02-25 23:58:31'),
(151, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2025-08-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_065855_8e79dd60.jpg', 'pago_1_20260226_065855_8e79dd60.jpg', 'pagado', NULL, 7, '2026-02-25 23:58:55', '2026-02-25 23:58:55'),
(152, 2, 16, 'Sueldo Carlos Jafeth Padilla — 2ª Quincena', 6600.00, '2025-08-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_065907_d2c69d45.jpg', 'pago_2_20260226_065907_d2c69d45.jpg', 'pagado', NULL, 7, '2026-02-25 23:59:07', '2026-02-25 23:59:07'),
(153, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 2ª Quincena', 3500.00, '2025-08-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_065919_63819892.jpg', 'pago_3_20260226_065919_63819892.jpg', 'pagado', NULL, 7, '2026-02-25 23:59:19', '2026-02-25 23:59:19'),
(154, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2025-09-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_065949_6da37447.jpg', 'pago_1_20260226_065949_6da37447.jpg', 'pagado', NULL, 7, '2026-02-25 23:59:49', '2026-02-25 23:59:49'),
(155, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 1ª Quincena', 3500.00, '2025-09-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_070000_ede5ef14.jpg', 'pago_3_20260226_070000_ede5ef14.jpg', 'pagado', NULL, 7, '2026-02-26 00:00:00', '2026-02-26 00:00:00'),
(156, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 2ª Quincena', 3500.00, '2025-09-29', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_070035_2793c862.jpg', 'pago_3_20260226_070035_2793c862.jpg', 'pagado', NULL, 7, '2026-02-26 00:00:35', '2026-02-26 00:00:35'),
(157, 2, 16, 'Sueldo Carlos Jafeth Padilla — 2ª Quincena', 6600.00, '2025-09-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_070052_2e8f5fc5.jpg', 'pago_2_20260226_070052_2e8f5fc5.jpg', 'pagado', NULL, 7, '2026-02-26 00:00:52', '2026-02-26 00:00:52'),
(158, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2025-09-29', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_070104_34b4cbde.jpg', 'pago_1_20260226_070104_34b4cbde.jpg', 'pagado', NULL, 7, '2026-02-26 00:01:04', '2026-02-26 00:01:04'),
(159, 2, 16, 'Sueldo Carlos Jafeth Padilla — 1ª Quincena', 6600.00, '2025-09-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_070216_4cdc4235.jpg', 'pago_2_20260226_070216_4cdc4235.jpg', 'pagado', NULL, 7, '2026-02-26 00:02:16', '2026-02-26 00:02:16'),
(160, 2, 16, 'Sueldo Carlos Jafeth Padilla — 1ª Quincena', 6600.00, '2025-10-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_070242_483e5cd0.jpg', 'pago_2_20260226_070242_483e5cd0.jpg', 'pagado', NULL, 7, '2026-02-26 00:02:42', '2026-02-26 00:02:42'),
(161, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 1ª Quincena', 3500.00, '2025-10-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_070255_ce383d33.jpg', 'pago_3_20260226_070255_ce383d33.jpg', 'pagado', NULL, 7, '2026-02-26 00:02:55', '2026-02-26 00:02:55'),
(162, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2025-10-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_070308_d907cc30.jpg', 'pago_1_20260226_070308_d907cc30.jpg', 'pagado', NULL, 7, '2026-02-26 00:03:08', '2026-02-26 00:03:08'),
(163, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2025-10-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_070322_974f82bf.jpg', 'pago_1_20260226_070322_974f82bf.jpg', 'pagado', NULL, 7, '2026-02-26 00:03:22', '2026-02-26 00:03:22'),
(165, 2, 16, 'Sueldo Carlos Jafeth Padilla — 2ª Quincena', 6100.00, '2025-10-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_070642_e73522e5.jpg', 'pago_2_20260226_070642_e73522e5.jpg', 'pagado', NULL, 7, '2026-02-26 00:06:42', '2026-02-26 00:06:42'),
(166, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 2ª Quincena', 3500.00, '2025-10-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_070654_0635e14e.jpg', 'pago_3_20260226_070654_0635e14e.jpg', 'pagado', NULL, 7, '2026-02-26 00:06:54', '2026-02-26 00:06:54'),
(167, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2025-11-14', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_070711_36e33f0f.jpg', 'pago_1_20260226_070711_36e33f0f.jpg', 'pagado', NULL, 7, '2026-02-26 00:07:11', '2026-02-26 00:07:11'),
(169, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 1ª Quincena', 3500.00, '2025-11-14', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_070846_4aad87dc.jpg', 'pago_3_20260226_070846_4aad87dc.jpg', 'pagado', NULL, 7, '2026-02-26 00:08:46', '2026-02-26 00:08:46'),
(170, 2, 16, 'Sueldo Carlos Jafeth Padilla — 1ª Quincena', 6100.00, '2025-11-14', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_070858_c96f8a83.jpg', 'pago_2_20260226_070858_c96f8a83.jpg', 'pagado', NULL, 7, '2026-02-26 00:08:58', '2026-02-26 00:34:48'),
(171, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2025-11-28', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_070921_8b409c08.jpg', 'pago_1_20260226_070921_8b409c08.jpg', 'pagado', NULL, 7, '2026-02-26 00:09:21', '2026-02-26 00:09:21'),
(172, 2, 16, 'Sueldo Carlos Jafeth Padilla — 2ª Quincena', 6100.00, '2025-11-28', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_070934_313d1dd3.jpg', 'pago_2_20260226_070934_313d1dd3.jpg', 'pagado', NULL, 7, '2026-02-26 00:09:34', '2026-02-26 00:09:34'),
(173, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 2ª Quincena', 3500.00, '2025-11-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_070946_a82dcfb5.jpg', 'pago_3_20260226_070946_a82dcfb5.jpg', 'pagado', NULL, 7, '2026-02-26 00:09:46', '2026-02-26 00:09:46'),
(174, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 1ª Quincena', 3500.00, '2025-12-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_071030_a17ff71a.jpg', 'pago_3_20260226_071030_a17ff71a.jpg', 'pagado', NULL, 7, '2026-02-26 00:10:30', '2026-02-26 00:10:30'),
(175, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 2ª Quincena', 3500.00, '2025-12-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_071113_a83be678.jpg', 'pago_3_20260226_071113_a83be678.jpg', 'pagado', NULL, 7, '2026-02-26 00:11:13', '2026-02-26 00:11:13'),
(176, 2, 16, 'Sueldo Carlos Jafeth Padilla — 2ª Quincena', 7000.00, '2025-12-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_071134_4036a942.jpg', 'pago_2_20260226_071134_4036a942.jpg', 'pagado', NULL, 7, '2026-02-26 00:11:34', '2026-02-26 00:11:34'),
(177, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2025-12-31', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_071158_c4f17851.jpg', 'pago_1_20260226_071158_c4f17851.jpg', 'pagado', NULL, 7, '2026-02-26 00:11:58', '2026-02-26 00:11:58'),
(178, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 1ª Quincena', 3500.00, '2026-01-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_071219_2a55bd76.jpg', 'pago_3_20260226_071219_2a55bd76.jpg', 'pagado', NULL, 7, '2026-02-26 00:12:19', '2026-02-26 00:12:19'),
(179, 2, 12, 'UPS Tripplite/Eaton OmniSmart LCD 120V', 6072.00, '2026-01-12', 'unico', NULL, NULL, NULL, NULL, 'variable', 'efectivo', NULL, 'Representaciones Lufergo S. de R. L. de C.V.', '000-003-01-00005014', NULL, NULL, NULL, NULL, 'pagado', NULL, 7, '2026-02-26 00:14:51', '2026-02-26 00:14:51'),
(186, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 16000.00, '2025-12-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_075110_b2b92702.jpg', 'pago_1_20260226_075110_b2b92702.jpg', 'pagado', NULL, 7, '2026-02-26 00:51:10', '2026-02-26 00:51:10'),
(187, 2, 16, 'Bono: Bono Navideño — Danny Sinoé Velásquez Cadenas', 4000.00, '2025-12-15', 'unico', NULL, NULL, NULL, NULL, 'fijo', 'transferencia', NULL, NULL, NULL, 'Aplicado junto con nómina gasto #186', NULL, NULL, NULL, 'pagado', NULL, 7, '2026-02-26 00:51:10', '2026-02-26 00:51:10'),
(188, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2025-03-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_075707_9d4d70a4.jpg', 'pago_1_20260226_075707_9d4d70a4.jpg', 'pagado', NULL, 7, '2026-02-26 00:57:07', '2026-02-26 00:57:07'),
(189, 2, 16, 'Sueldo Alba Isabel Gusman Ordoñez — 1ª Quincena', 4000.00, '2025-02-14', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_5_20260226_075927_82a779b1.jpg', 'pago_5_20260226_075927_82a779b1.jpg', 'pagado', NULL, 7, '2026-02-26 00:59:27', '2026-02-26 00:59:27'),
(190, 2, 16, 'Sueldo Carlos Jafeth Padilla — 1ª Quincena', 6100.00, '2025-07-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_080607_b31ea28d.jpg', 'pago_2_20260226_080607_b31ea28d.jpg', 'pagado', NULL, 7, '2026-02-26 01:06:07', '2026-02-26 01:06:07'),
(191, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 2ª Quincena', 3500.00, '2026-01-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_3_20260226_080750_a5c8f8be.jpg', 'pago_3_20260226_080750_a5c8f8be.jpg', 'pagado', NULL, 7, '2026-02-26 01:07:50', '2026-02-26 01:07:50'),
(192, 2, 16, 'Sueldo Carlos Jafeth Padilla — 1ª Quincena', 12100.00, '2025-12-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_081905_0f26700c.jpg', 'pago_2_20260226_081905_0f26700c.jpg', 'pagado', NULL, 7, '2026-02-26 01:19:05', '2026-02-26 01:19:05'),
(193, 2, 16, 'Bono: Bono Navideño — Carlos Jafeth Padilla', 6000.00, '2025-12-15', 'unico', NULL, NULL, NULL, NULL, 'fijo', 'transferencia', NULL, NULL, NULL, 'Aplicado junto con nómina gasto #192', NULL, NULL, NULL, 'pagado', NULL, 7, '2026-02-26 01:19:05', '2026-02-26 01:19:05'),
(194, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2025-04-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_212906_dd16a9ce.jpg', 'pago_1_20260226_212906_dd16a9ce.jpg', 'pagado', NULL, 7, '2026-02-26 14:29:06', '2026-02-26 14:29:06'),
(195, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2025-04-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_214209_33b0c1af.jpg', 'pago_1_20260226_214209_33b0c1af.jpg', 'pagado', NULL, 7, '2026-02-26 14:42:09', '2026-02-26 14:42:09'),
(196, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2025-05-14', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_214240_510cfe61.jpg', 'pago_1_20260226_214240_510cfe61.jpg', 'pagado', NULL, 7, '2026-02-26 14:42:40', '2026-02-26 14:42:40'),
(197, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2025-07-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_1_20260226_214707_2d0e25fe.jpg', 'pago_1_20260226_214707_2d0e25fe.jpg', 'pagado', NULL, 7, '2026-02-26 14:47:07', '2026-02-26 14:47:07'),
(198, 2, 16, 'Sueldo Carlos Jafeth Padilla — 2ª Quincena', 7000.00, '2026-01-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/02/pago_2_20260226_214804_4d9f6533.jpg', 'pago_2_20260226_214804_4d9f6533.jpg', 'pagado', NULL, 7, '2026-02-26 14:48:04', '2026-02-26 14:48:04'),
(208, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-01-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'transferencia', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', 'gasto_2_20260226_220923_0218cc34.jpeg', 'WhatsApp Image 2026-02-26 at 15.03.41.jpeg', 'pagado', NULL, 7, '2026-02-26 15:02:37', '2026-02-26 15:09:23'),
(209, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-02-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'transferencia', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', 'gasto_2_20260226_220943_c1e66088.jpg', 'PHOTO-2026-02-05-18-35-52.jpg', 'pagado', NULL, 7, '2026-02-26 15:02:37', '2026-02-26 15:09:43'),
(210, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-03-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'efectivo', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', 'gasto_2_20260326_044816_738433e5.jpg', 'PHOTO-2026-03-05-12-24-41.jpg', 'pagado', NULL, 7, '2026-02-26 15:02:37', '2026-03-30 10:17:45'),
(211, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-04-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'transferencia', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', NULL, NULL, 'pendiente', NULL, 7, '2026-02-26 15:02:37', '2026-02-26 15:02:37'),
(212, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-05-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'transferencia', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', NULL, NULL, 'pendiente', NULL, 7, '2026-02-26 15:02:37', '2026-02-26 15:02:37'),
(213, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-06-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'transferencia', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', NULL, NULL, 'pendiente', NULL, 7, '2026-02-26 15:02:37', '2026-02-26 15:02:37'),
(214, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-07-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'transferencia', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', NULL, NULL, 'pendiente', NULL, 7, '2026-02-26 15:02:37', '2026-02-26 15:02:37'),
(215, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-08-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'transferencia', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', NULL, NULL, 'pendiente', NULL, 7, '2026-02-26 15:02:37', '2026-02-26 15:02:37'),
(216, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-09-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'transferencia', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', NULL, NULL, 'pendiente', NULL, 7, '2026-02-26 15:02:37', '2026-02-26 15:02:37'),
(217, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-10-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'transferencia', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', NULL, NULL, 'pendiente', NULL, 7, '2026-02-26 15:02:37', '2026-02-26 15:02:37'),
(218, 2, 12, 'Pago  préstamo Fundacion THRIIVE', 2152.18, '2026-11-05', 'mensual', 5, NULL, 208, NULL, 'fijo', 'transferencia', NULL, 'THRIIVE - FUNDER', NULL, 'Thriive nos brindará nota justificando esto.', '2026-11-05', NULL, NULL, 'pendiente', NULL, 7, '2026-02-26 15:02:37', '2026-02-26 15:02:37'),
(219, 2, 10, 'Pago Hosting Godaddy - naranjaymediahn.com', 8597.44, '2026-03-03', 'anual', NULL, NULL, NULL, NULL, 'fijo', 'efectivo', NULL, 'GODADDY', NULL, 'PAGO CON TARJETA DE NARANJA', '2027-03-03', 'gasto_2_20260326_045226_7bd0061a.jpg', 'Mi cuenta _ Facturación_page-0001.jpg', 'pagado', NULL, 7, '2026-02-26 15:23:06', '2026-03-25 23:39:19'),
(220, 2, 16, 'Sueldo Carlos Jafeth Padilla — 2ª Quincena', 6500.00, '2026-02-28', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/03/pago_2_20260302_192200_cfb84d64.jpg', 'pago_2_20260302_192200_cfb84d64.jpg', 'pagado', NULL, 7, '2026-03-02 12:22:00', '2026-03-02 12:22:00'),
(221, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2026-02-27', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/03/pago_1_20260302_192212_8299e770.jpg', 'pago_1_20260302_192212_8299e770.jpg', 'pagado', NULL, 7, '2026-03-02 12:22:12', '2026-03-02 12:22:12'),
(222, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 2ª Quincena', 3500.00, '2026-02-27', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/03/pago_3_20260302_192223_0fb4b97d.jpg', 'pago_3_20260302_192223_0fb4b97d.jpg', 'pagado', NULL, 7, '2026-03-02 12:22:23', '2026-03-02 12:22:23'),
(223, 2, 16, 'Sueldo Carlos Jafeth Padilla — 1ª Quincena', 6500.00, '2026-03-13', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/03/pago_2_20260313_171414_4327a38a.jpg', 'pago_2_20260313_171414_4327a38a.jpg', 'pagado', NULL, 7, '2026-03-13 10:14:14', '2026-03-13 10:14:14'),
(224, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 1ª Quincena', 12000.00, '2026-03-13', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/03/pago_1_20260313_171448_53426ed7.jpg', 'pago_1_20260313_171448_53426ed7.jpg', 'pagado', NULL, 7, '2026-03-13 10:14:48', '2026-03-13 10:14:48'),
(225, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 1ª Quincena', 3500.00, '2026-03-13', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/03/pago_3_20260313_171456_de9425e4.jpg', 'pago_3_20260313_171456_de9425e4.jpg', 'pagado', NULL, 7, '2026-03-13 10:14:56', '2026-03-13 10:14:56'),
(226, 2, 16, 'Sueldo Carlos Jafeth Padilla — 1ª Quincena', 9100.00, '2026-01-15', 'quincenal', 15, 30, NULL, 1, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/03/pago_2_20260326_220252_29debc68.png', 'pago_2_20260326_220252_29debc68.png', 'pagado', NULL, 7, '2026-03-26 15:02:52', '2026-03-26 15:02:52'),
(228, 2, 16, 'Sueldo Carlos Jafeth Padilla — 2ª Quincena', 6500.00, '2026-03-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/03/pago_2_20260330_175847_aef31a7c.jpg', 'pago_2_20260330_175847_aef31a7c.jpg', 'pagado', NULL, 7, '2026-03-30 09:58:47', '2026-03-30 09:58:47'),
(229, 2, 16, 'Sueldo Danny Sinoé Velásquez Cadenas — 2ª Quincena', 12000.00, '2026-03-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/03/pago_1_20260330_175901_eab09204.jpg', 'pago_1_20260330_175901_eab09204.jpg', 'pagado', NULL, 7, '2026-03-30 09:59:01', '2026-03-30 09:59:01'),
(230, 2, 11, 'Combustible 30 de marzo, viaje a tela', 1614.90, '2026-03-30', 'unico', NULL, NULL, NULL, NULL, 'variable', 'tarjeta', 1, 'Danny Sinoé Velásquez Cadenas', NULL, NULL, NULL, 'gasto_2_20260330_185425_31cc7e54.jpg', 'IMG_0748.jpg', 'pagado', NULL, 7, '2026-03-30 10:00:41', '2026-03-30 11:05:55'),
(231, 2, 14, 'Desayuno Viaje a Tela', 188.00, '2026-03-30', 'unico', NULL, NULL, NULL, NULL, 'variable', 'tarjeta', 1, 'Danny Sinoé Velásquez Cadenas', NULL, NULL, NULL, 'gasto_2_20260330_184152_f5bb8cbd.jpg', 'IMG_0747.jpg', 'pagado', NULL, 7, '2026-03-30 10:01:14', '2026-03-30 11:05:32'),
(232, 2, 16, 'Sueldo Jazmin Alejandra Andreus Osorio — 2ª Quincena', 3500.00, '2026-03-30', 'quincenal', 15, 30, NULL, 2, 'fijo', 'transferencia', NULL, NULL, NULL, NULL, NULL, '2026/03/pago_3_20260330_180705_0b70756a.jpg', 'pago_3_20260330_180705_0b70756a.jpg', 'pagado', NULL, 7, '2026-03-30 10:07:05', '2026-03-30 10:07:05'),
(233, 2, 10, 'Business Web Hosting - SIGURBAN.COM', 4170.00, '2026-03-29', 'anual', NULL, NULL, NULL, NULL, 'variable', 'efectivo', NULL, 'Danny Sinoé Velásquez Cadenas', NULL, NULL, NULL, NULL, NULL, 'pagado', NULL, 7, '2026-03-30 10:10:38', '2026-03-30 10:11:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `municipios`
--

CREATE TABLE `municipios` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `departamento_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `municipios`
--

INSERT INTO `municipios` (`id`, `nombre`, `departamento_id`) VALUES
(1, 'La Ceiba', 1),
(2, 'El Porvenir', 1),
(3, 'Esparta', 1),
(4, 'Jutiapa', 1),
(5, 'La Masica', 1),
(6, 'San Francisco', 1),
(7, 'Tela', 1),
(8, 'Arizona', 1),
(9, 'Trujillo', 2),
(10, 'Balfate', 2),
(11, 'Iriona', 2),
(12, 'Limón', 2),
(13, 'Sabá', 2),
(14, 'Santa Fe', 2),
(15, 'Santa Rosa de Aguán', 2),
(16, 'Sonaguera', 2),
(17, 'Tocoa', 2),
(18, 'Comayagua', 3),
(19, 'Ajuterique', 3),
(20, 'El Rosario', 3),
(21, 'Esquías', 3),
(22, 'Humuya', 3),
(23, 'La Libertad', 3),
(24, 'Lamaní', 3),
(25, 'La Trinidad', 3),
(26, 'Lejamaní', 3),
(27, 'Meámbar', 3),
(28, 'Minas de Oro', 3),
(29, 'Ojos de Agua', 3),
(30, 'San Jerónimo', 3),
(31, 'San José de Comayagua', 3),
(32, 'San José del Potrero', 3),
(33, 'San Luis', 3),
(34, 'San Sebastián', 3),
(35, 'Siguatepeque', 3),
(36, 'Taulabé', 3),
(37, 'Villa de San Antonio', 3),
(38, 'Santa Rosa de Copán', 4),
(39, 'Cabañas', 4),
(40, 'Concepción', 4),
(41, 'Copán Ruinas', 4),
(42, 'Corquín', 4),
(43, 'Cucuyagua', 4),
(44, 'Dolores', 4),
(45, 'Dulce Nombre', 4),
(46, 'El Paraíso', 4),
(47, 'Florida', 4),
(48, 'La Jigua', 4),
(49, 'La Unión', 4),
(50, 'Nueva Arcadia', 4),
(51, 'San Agustín', 4),
(52, 'San Antonio', 4),
(53, 'San Jerónimo', 4),
(54, 'San José', 4),
(55, 'San Juan de Opoa', 4),
(56, 'San Nicolás', 4),
(57, 'San Pedro', 4),
(58, 'Santa Rita', 4),
(59, 'Trinidad de Copán', 4),
(60, 'Veracruz', 4),
(61, 'San Pedro Sula', 5),
(62, 'Choloma', 5),
(63, 'La Lima', 5),
(64, 'Puerto Cortés', 5),
(65, 'Villanueva', 5),
(66, 'Pimienta', 5),
(67, 'Potrerillos', 5),
(68, 'San Antonio de Cortés', 5),
(69, 'San Francisco de Yojoa', 5),
(70, 'San Manuel', 5),
(71, 'Santa Cruz de Yojoa', 5),
(72, 'Omoa', 5),
(73, 'Choluteca', 6),
(74, 'Apacilagua', 6),
(75, 'Concepción de María', 6),
(76, 'Duyure', 6),
(77, 'El Corpus', 6),
(78, 'El Triunfo', 6),
(79, 'Marcovia', 6),
(80, 'Morolica', 6),
(81, 'Namasigüe', 6),
(82, 'Orocuina', 6),
(83, 'Pespire', 6),
(84, 'San Antonio de Flores', 6),
(85, 'San Isidro', 6),
(86, 'San José', 6),
(87, 'San Marcos de Colón', 6),
(88, 'Yuscarán', 7),
(89, 'Alauca', 7),
(90, 'Danlí', 7),
(91, 'El Paraíso', 7),
(92, 'Güinope', 7),
(93, 'Jacaleapa', 7),
(94, 'Liure', 7),
(95, 'Morocelí', 7),
(96, 'Oropolí', 7),
(97, 'Potrerillos', 7),
(98, 'San Antonio de Flores', 7),
(99, 'San Lucas', 7),
(100, 'San Matías', 7),
(101, 'Soledad', 7),
(102, 'Teupasenti', 7),
(103, 'Texiguat', 7),
(104, 'Vado Ancho', 7),
(105, 'Yauyupe', 7),
(106, 'Trojes', 7),
(107, 'Distrito Central', 8),
(108, 'Alubarén', 8),
(109, 'Cedros', 8),
(110, 'Curarén', 8),
(111, 'El Porvenir', 8),
(112, 'Guaimaca', 8),
(113, 'La Libertad', 8),
(114, 'La Venta', 8),
(115, 'Lepaterique', 8),
(116, 'Maraita', 8),
(117, 'Marale', 8),
(118, 'Nueva Armenia', 8),
(119, 'Ojojona', 8),
(120, 'Orica', 8),
(121, 'Reitoca', 8),
(122, 'Sabanagrande', 8),
(123, 'San Antonio de Oriente', 8),
(124, 'San Buenaventura', 8),
(125, 'San Ignacio', 8),
(126, 'San Juan de Flores', 8),
(127, 'San Miguelito', 8),
(128, 'Santa Ana', 8),
(129, 'Santa Lucía', 8),
(130, 'Talanga', 8),
(131, 'Tatumbla', 8),
(132, 'Valle de Ángeles', 8),
(133, 'Vallecillo', 8),
(134, 'Villa de San Francisco', 8),
(135, 'Puerto Lempira', 9),
(136, 'Brus Laguna', 9),
(137, 'Ahuas', 9),
(138, 'Juan Francisco Bulnes', 9),
(139, 'Ramón Villeda Morales', 9),
(140, 'Wampusirpi', 9),
(141, 'La Esperanza', 10),
(142, 'Camasca', 10),
(143, 'Colomoncagua', 10),
(144, 'Concepción', 10),
(145, 'Dolores', 10),
(146, 'Intibucá', 10),
(147, 'Jesús de Otoro', 10),
(148, 'Magdalena', 10),
(149, 'Masaguara', 10),
(150, 'San Antonio', 10),
(151, 'San Isidro', 10),
(152, 'San Juan', 10),
(153, 'San Marcos de la Sierra', 10),
(154, 'San Miguelito', 10),
(155, 'Santa Lucía', 10),
(156, 'Yamaranguila', 10),
(157, 'Roatán', 11),
(158, 'Guanaja', 11),
(159, 'José Santos Guardiola', 11),
(160, 'Utila', 11),
(161, 'La Paz', 12),
(162, 'Aguanqueterique', 12),
(163, 'Cabañas', 12),
(164, 'Cane', 12),
(165, 'Chinacla', 12),
(166, 'Guajiquiro', 12),
(167, 'Lauterique', 12),
(168, 'Marcala', 12),
(169, 'Mercedes de Oriente', 12),
(170, 'Opatoro', 12),
(171, 'San Antonio del Norte', 12),
(172, 'San José', 12),
(173, 'San Juan', 12),
(174, 'San Pedro de Tutule', 12),
(175, 'Santa Ana', 12),
(176, 'Santa Elena', 12),
(177, 'Santa María', 12),
(178, 'Santiago de Puringla', 12),
(179, 'Yarula', 12),
(180, 'Gracias', 13),
(181, 'Belén', 13),
(182, 'Candelaria', 13),
(183, 'Cololaca', 13),
(184, 'Erandique', 13),
(185, 'Gualcince', 13),
(186, 'Guarita', 13),
(187, 'La Campa', 13),
(188, 'La Iguala', 13),
(189, 'Las Flores', 13),
(190, 'La Unión', 13),
(191, 'La Virtud', 13),
(192, 'Lepaera', 13),
(193, 'Mapulaca', 13),
(194, 'Piraera', 13),
(195, 'San Andrés', 13),
(196, 'San Francisco', 13),
(197, 'San Juan Guarita', 13),
(198, 'San Manuel Colohete', 13),
(199, 'San Rafael', 13),
(200, 'San Sebastián', 13),
(201, 'Santa Cruz', 13),
(202, 'Talgua', 13),
(203, 'Tambla', 13),
(204, 'Tomalá', 13),
(205, 'Valladolid', 13),
(206, 'Virginia', 13),
(207, 'San Marcos de Caiquín', 13),
(208, 'Nueva Ocotepeque', 14),
(209, 'Belén Gualcho', 14),
(210, 'Concepción', 14),
(211, 'Dolores Merendón', 14),
(212, 'Fraternidad', 14),
(213, 'La Encarnación', 14),
(214, 'La Labor', 14),
(215, 'Lucerna', 14),
(216, 'Mercedes', 14),
(217, 'San Fernando', 14),
(218, 'San Francisco del Valle', 14),
(219, 'San Jorge', 14),
(220, 'San Marcos', 14),
(221, 'Santa Fe', 14),
(222, 'Sensenti', 14),
(223, 'Sinuapa', 14),
(224, 'Juticalpa', 15),
(225, 'Campamento', 15),
(226, 'Catacamas', 15),
(227, 'Concordia', 15),
(228, 'Dulce Nombre de Culmí', 15),
(229, 'El Rosario', 15),
(230, 'Esquipulas del Norte', 15),
(231, 'Gualaco', 15),
(232, 'Guarizama', 15),
(233, 'Guata', 15),
(234, 'Guayape', 15),
(235, 'Jano', 15),
(236, 'La Unión', 15),
(237, 'Mangulile', 15),
(238, 'Manto', 15),
(239, 'Salamá', 15),
(240, 'San Esteban', 15),
(241, 'San Francisco de Becerra', 15),
(242, 'San Francisco de la Paz', 15),
(243, 'Santa María del Real', 15),
(244, 'Silca', 15),
(245, 'Yocón', 15),
(246, 'Patuca', 15),
(247, 'Santa Bárbara', 16),
(248, 'Arada', 16),
(249, 'Atima', 16),
(250, 'Azacualpa', 16),
(251, 'Ceguaca', 16),
(252, 'Concepción del Norte', 16),
(253, 'Concepción del Sur', 16),
(254, 'Chinda', 16),
(255, 'El Níspero', 16),
(256, 'Gualala', 16),
(257, 'Ilama', 16),
(258, 'Macuelizo', 16),
(259, 'Naranjito', 16),
(260, 'Nuevo Celilac', 16),
(261, 'Petoa', 16),
(262, 'Protección', 16),
(263, 'Quimistán', 16),
(264, 'San Francisco de Ojuera', 16),
(265, 'San José de Colinas', 16),
(266, 'San Luis', 16),
(267, 'San Marcos', 16),
(268, 'San Nicolás', 16),
(269, 'San Pedro Zacapa', 16),
(270, 'Santa Rita', 16),
(271, 'Trinidad', 16),
(272, 'Las Vegas', 16),
(273, 'Nueva Frontera', 16),
(274, 'San Vicente Centenario', 16),
(275, 'La Libertad', 16),
(276, 'San Rafael', 16),
(277, 'Nacaome', 17),
(278, 'Alianza', 17),
(279, 'Amapala', 17),
(280, 'Aramecina', 17),
(281, 'Caridad', 17),
(282, 'Goascorán', 17),
(283, 'Langue', 17),
(284, 'San Francisco de Coray', 17),
(285, 'San Lorenzo', 17),
(286, 'Yoro', 18),
(287, 'Arenal', 18),
(288, 'El Negrito', 18),
(289, 'El Progreso', 18),
(290, 'Jocón', 18),
(291, 'Morazán', 18),
(292, 'Olanchito', 18),
(293, 'Santa Rita', 18),
(294, 'Sulaco', 18),
(295, 'Victoria', 18),
(296, 'Yorito', 18);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `paises`
--

CREATE TABLE `paises` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `paises`
--

INSERT INTO `paises` (`id`, `nombre`) VALUES
(1, 'Honduras');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `precios_especiales`
--

CREATE TABLE `precios_especiales` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `precio_especial` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `precios_especiales`
--

INSERT INTO `precios_especiales` (`id`, `cliente_id`, `producto_id`, `precio_especial`) VALUES
(7, 2, 4, 21000.00),
(8, 2, 6, 43000.00),
(9, 2, 16, 14500.00),
(10, 2, 17, 27000.00),
(11, 2, 1, 4000.00),
(12, 2, 2, 14000.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos`
--

CREATE TABLE `productos` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `nombre` varchar(400) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `precio` decimal(10,2) DEFAULT NULL,
  `tipo_isv` enum('15','18','0') NOT NULL DEFAULT '15'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `productos`
--

INSERT INTO `productos` (`id`, `cliente_id`, `nombre`, `descripcion`, `precio`, `tipo_isv`) VALUES
(1, 2, 'Soporte Sitio Web', 'Servicio de soporte web mensual', 4501.00, '15'),
(2, 2, 'Estrategia Marketing Digital', 'Estrategia personalizada para medios digitales', 15500.00, '15'),
(3, 2, 'Generación de bandas PDF', 'Generación de bandas en formato PDF', 2.50, '15'),
(4, 2, 'Sitio web Básico', 'Diseño y Desarrollo Web, Optimización SEO', 23000.00, '15'),
(5, 2, 'Sitio web Intermedio', 'Diseño y Desarrollo Web, Optimización Avanzada del SEO y Seguridad', 35000.00, '15'),
(6, 2, 'Sitio web Ecommerce', 'Diseño y Desarrollo Web, Optimización Avanzada del SEO y Seguridad con ecommerce', 45000.00, '15'),
(7, 2, 'CRM', 'Software de CRM en modelo de renta mensual', 6500.00, '15'),
(8, 2, 'Creación de Marca', 'Diseño de logotipo y branding básico', 6500.00, '15'),
(9, 2, 'Manual de Identidad Básico', 'Manual básico de identidad visual', 2787.67, '15'),
(10, 2, 'Manual de Identidad Intermedio', 'Manual de identidad intermedio', 16000.00, '15'),
(11, 2, 'Manual de Identidad Completo', 'Manual completo de identidad corporativa', 30000.00, '15'),
(12, 2, 'Artes para redes sociales', 'Diseños gráficos para publicaciones', 550.00, '15'),
(13, 2, 'Diseño de Vallas Publicitarias', 'Diseño de vallas y material exterior', 1500.00, '15'),
(14, 2, 'Sesión fotográfica', 'Sesión fotográfica profesional', 7500.00, '15'),
(15, 2, 'Video Corporativo', 'Producción de video corporativo', 55000.00, '15'),
(16, 2, 'Video Institucional', 'Producción de video institucional', 30000.00, '15'),
(17, 2, 'Video Animación 2D 30 segundos', 'Animación 2D de 30 segundos', 17000.00, '15'),
(18, 2, 'Video Animación 2D 1 minuto', 'Animación 2D de 1 minuto', 30000.00, '15'),
(19, 2, 'Video Animación 2D 1:30 minutos', 'Animación 2D de 1:30 minutos', 35000.00, '15'),
(20, 2, 'Video Animación 2D > 2 minutos', 'Animación 2D de más de 2 minutos', 40000.00, '15'),
(21, 2, 'Spot Publicitario', 'Producción de spot publicitario', 40000.00, '15'),
(22, 2, 'Diseño UX y UI Ilustrado App o Web', 'Diseño UX/UI con ilustraciones personalizadas', 28000.00, '15'),
(23, 2, 'Diseño UX y UI Figma App o Web', 'Diseño UX/UI prototipado en Figma', 33000.00, '15'),
(24, 2, 'Adaptación de Diseño', 'Adaptación de Diseño PSD o IA', 100.00, '15'),
(25, 2, 'Edición de video Básico RRSS', 'Edición de video (básico RRSS)', 300.00, '15'),
(26, 2, 'REDISEÑO/ADAPTACIÓN', 'REDISEÑO/ADAPTACIÓN', 150.00, '15'),
(27, 2, 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 16966.91, '15'),
(28, 2, 'Locución para video corporativo (40 segundos)', 'Locución para video corporativo (40 segundos)', 2000.00, '15'),
(29, 2, 'Diseño y Desarrollo Web, Optimización Avanzada del SEO y Seguridad', 'Diseño y Desarrollo Web, Optimización Avanzada del SEO y Seguridad', 28676.67, '15'),
(30, 2, 'Curso Básico de Marketing Digital para Emprendedores (2hrs)', 'Curso Básico de Marketing Digital para Emprendedores\r\n', 3000.00, '15'),
(31, 2, 'Generación de estrategia, Manejo de redes Sociales y todas las publicaciones, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 7500.00, '15'),
(32, 2, 'Generación de estrategia, Manejo de redes Sociales y todas las publicaciones, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 11500.00, '15'),
(33, 2, 'Soporte Sitio Web', NULL, 3500.00, '15'),
(34, 2, 'Gestión de redes sociales', NULL, 7000.00, '15'),
(35, 2, 'Estrategia de Marketing Digital, Generación de Contenido y Manejo de Pauta en Social Media.', NULL, 9000.00, '15'),
(36, 2, 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 15500.00, '15'),
(37, 2, 'Envío de mensajería masiva - Whatsapp/Correo', NULL, 2.00, '15'),
(38, 2, 'Generación de Documentos con QR a PDF/JPG', NULL, 0.50, '15'),
(39, 2, 'Soporte Sitio Web', NULL, 3000.00, '15'),
(40, 2, 'IMPRESIÓN DE BOLETERíA DEPORTIVA (2.5\"X5\")CON CORRELATIVO Y CÓDIGO QR', 'BOLETERÍA DE DEPORTIVA', 3.00, '15'),
(41, 2, 'FEED POR EVENTO', NULL, 1.70, '15'),
(42, 2, 'SOPORTE APP ESCANEO', NULL, 0.00, '15'),
(43, 2, 'DISEÑO GRÁFICO DE BANNER ARAÑA', NULL, 400.00, '15'),
(44, 2, 'SOPORTE TÉCNICO Y RENTA DE SCANNERS', NULL, 600.00, '15');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos_clientes`
--

CREATE TABLE `productos_clientes` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `receptores_id` int(11) DEFAULT NULL,
  `nombre` varchar(400) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `precio` decimal(12,3) NOT NULL DEFAULT 0.000,
  `tipo_isv` enum('15','18','0') NOT NULL DEFAULT '15',
  `precio_fijo` tinyint(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `productos_clientes`
--

INSERT INTO `productos_clientes` (`id`, `cliente_id`, `receptores_id`, `nombre`, `descripcion`, `precio`, `tipo_isv`, `precio_fijo`) VALUES
(1, 2, 1, 'Soporte Sitio Web', 'Servicio de soporte web mensual', 3000.000, '15', 1),
(3, 2, 1, 'Generación de bandas PDF', 'Generación de bandas en formato PDF', 2.500, '15', 1),
(4, 2, 23, 'NULO', 'NULO', 0.000, '15', 1),
(24, 2, 1, 'Adaptación de Diseño', 'Adaptación de Diseño PSD o IA', 150.000, '15', 1),
(25, 2, 1, 'Edición de video Básico RRSS', 'Edición de video (básico RRSS)', 300.000, '15', 1),
(26, 2, 1, 'REDISEÑO/ADAPTACIÓN', 'REDISEÑO/ADAPTACIÓN', 150.000, '15', 1),
(27, 2, 2, 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 16966.910, '15', 1),
(28, 2, 2, 'Locución para video corporativo (40 segundos)', 'Locución para video corporativo (40 segundos)', 2000.000, '15', 1),
(29, 2, 7, 'Diseño y Desarrollo Web, Optimización Avanzada del SEO y Seguridad', 'Diseño y Desarrollo Web, Optimización Avanzada del SEO y Seguridad', 28676.670, '15', 1),
(30, 2, 8, 'Curso Básico de Marketing Digital para Emprendedores (2hrs)', 'Curso Básico de Marketing Digital para Emprendedores', 3000.000, '15', 1),
(31, 2, 17, 'Generación de estrategia, Manejo de redes Sociales y todas las publicaciones, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 7500.000, '15', 1),
(32, 2, 17, 'Generación de estrategia, Manejo de redes Sociales y todas las publicaciones, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 11500.000, '15', 1),
(33, 2, 17, 'Pauta Digital Google Ads', NULL, 0.000, '15', 0),
(34, 2, 17, 'Pauta Digital Meta Ads', NULL, 3691.000, '15', 0),
(35, 2, 1, 'Soporte Sitio Web', NULL, 3500.000, '15', 1),
(36, 2, 1, 'Gestión de redes sociales', NULL, 7000.000, '15', 1),
(37, 2, 6, 'Estrategia de Marketing Digital, Generación de Contenido y Manejo de Pauta en Social Media.', NULL, 9000.000, '15', 1),
(38, 2, 1, 'Envío de mensajería masiva - Whatsapp/Correo', NULL, 2.000, '15', 1),
(39, 2, 1, 'Generación de Documentos con QR a PDF/JPG', NULL, 0.500, '15', 1),
(40, 2, 1, 'Adaptación de Diseño', 'Adaptación de Diseño PSD o IA', 100.000, '15', 1),
(41, 2, 1, 'Soporte Sitio Web', NULL, 3000.000, '15', 1),
(42, 2, 1, 'DISEÑO GRÁFICO DE BANNER ARAÑA', NULL, 400.000, '15', 0),
(43, 2, 1, 'SOPORTE APP ESCANEO', NULL, 700.000, '15', 0),
(44, 2, 1, 'FEED POR EVENTO', NULL, 1.700, '15', 0),
(45, 2, 18, 'SOPORTE TÉCNICO Y RENTA DE SCANNERS', NULL, 600.000, '15', 0),
(46, 2, 6, 'DISEÑO GRÁFICO DE BANNER ARAÑA', NULL, 400.000, '15', 0),
(47, 2, 18, 'IMPRESIÓN DE BOLETERíA DEPORTIVA (2.5\"X5\")CON CORRELATIVO Y CÓDIGO QR', NULL, 3.000, '15', 0),
(48, 2, 5, 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', NULL, 15500.000, '15', 0),
(49, 2, 2, 'Pauta Digital Meta Ads', NULL, 0.000, '15', 0),
(50, 2, 5, 'Pauta Digital Meta Ads', NULL, 0.000, '15', 0),
(52, 2, 19, 'DESARROLLO SITIO WEB', 'GWM', 20300.000, '15', 1),
(53, 2, 19, 'DISEÑO GRÁFICO', 'FICHAS TÉCNICAS DE VEHÍCULOS PARA SITIO WEB FORMATO PDF EDITABLE', 850.000, '15', 1),
(54, 2, 20, 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 197000.000, '15', 1),
(55, 2, 21, 'Creación de Marca', 'Diseño de logotipo y branding básico', 7000.000, '15', 1),
(56, 2, 21, 'Manual de Identidad Básico', 'Manual básico de identidad visual', 7000.000, '15', 1),
(57, 2, 17, 'RENOVACIÓN DE BUSINESS HOSTING WEB', 'HOSTING WEB', 5993.790, '15', 1),
(58, 2, 22, 'MIGRACIÓN DE INFORMACIÓN WEB', 'WORDPRESS', 24750.000, '15', 1),
(59, 2, 22, 'DESARROLLO SITIO WEB', '.', 36750.000, '15', 1),
(60, 2, 5, 'DESARROLLO SITIO WEB', '.', 17600.000, '15', 1),
(61, 2, 1, 'REDISEÑO WEB', 'ACTUALIZACIÓN, REDISEÑO, LICENCIAS Y SEGURIDAD WEB', 15000.000, '15', 1),
(62, 2, 1, 'DESARROLLO Y SOPORTE DE SUSCRIPCIONES', 'DESARROLLO Y SOPORTE DE SUSCRIPCIONES', 1083.330, '15', 1),
(63, 2, 5, 'HOSTING Y DOMINIO WEB', 'HOSTING Y DOMINIO WEB', 4853.900, '15', 1),
(64, 2, 1, 'LICENCIA APP STORE', 'APP BMT', 0.000, '15', 1),
(65, 2, 1, 'LICENCIA PLAY STORE', 'APP BMT', 0.000, '15', 1),
(66, 2, 4, 'DESARROLLO SITIO WEB', 'POSICIONAMIENTO SEO, GOOGLE ANALYTICS, Y GOOGLE MY BUSINESS', 23000.000, '15', 1),
(67, 2, 2, 'PRODUCTOS GIVEAWAY', 'PRODUCTOS GIVEAWAY', 0.000, '15', 1),
(69, 2, 2, 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 15500.000, '15', 1),
(70, 2, 1, 'SOPORTE TÉCNICO', 'CUALQUIER', 3000.000, '15', 1),
(71, 2, 24, 'Soporte técnico especializado en red y servidor local', 'MANTENIMIENTO DE RED', 2043.480, '15', 1),
(72, 2, 18, 'IMPRESIÓN DE BOLETERíA DEPORTIVA (2.5\"X5\")CON CORRELATIVO Y CÓDIGO QR', NULL, 3.000, '15', 1),
(73, 2, 18, 'SOPORTE TÉCNICO Y RENTA DE SCANNERS', NULL, 600.000, '15', 1),
(74, 2, 1, 'CONSUMO GOOGLE CLOUD', 'FIREBASE', 1.000, '15', 1),
(75, 2, 25, 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia de marketing digital, generación de contenido, diseño, desarrollo y soporte de sitio Web, manejo de pauta y segmentacion, SEM, SEO y landing pages', 15500.000, '15', 1),
(76, 2, 25, 'HOSTING Y DOMINIO WEB', 'valerianohonduras.com', 3923.000, '15', 1),
(77, 2, 25, 'Pauta Digital Meta Ads', 'Pauta digital de promoción jueves, 9 de octubre,  2025', 326.940, '15', 1),
(78, 2, 26, 'Servicio de nuevo diseño de sitio web CODIS/SISA', 'Servicios profesionales de diseño y desarrollo de nuevo sitio web corporativo para CODIS y SISA, incluyendo UX/UI, estructura y carga de contenido, catálogo de marcas, blog/noticias, formularios de generación de leads (proveedores y distribuidores), panel de administración, optimización SEO e integración de analítica, conforme Contrato de Prestación de Servicios de fecha 18/03/2025.', 1.000, '15', 1),
(79, 2, 17, 'RENOVACIÓN DE BUSINESS HOSTING VPS CRM', 'RENOVACIÓN DE BUSINESS HOSTING VPS CRM', 10000.000, '15', 1),
(80, 2, 1, 'Pauta Digital Meta Ads', 'Pauta Digital Meta Ads', 1.000, '15', 1),
(81, 2, 1, 'Pauta Digital Google Ads', 'Pauta Digital Google Ads', 1.000, '15', 1),
(82, 2, 17, 'CUOTA CHATBOT', 'CUOTA CHATBOT', 1100.000, '15', 1),
(83, 2, 17, 'Desarrollo de CRM Inmobiliario', '-Dashboard Administrativo\r\n-Análisis y Diseño de Base de Datos\r\n-Módulo de Usuarios\r\n-Módulo de Mapa y Lotes\r\n-Módulo de Prospecto General\r\n-Módulo de Prospecto Definido\r\n-Test e Implementación', 162000.000, '15', 1),
(84, 2, 1, 'Servicio gestionado para la plataforma BMTicket | Interplay', 'Soporte continuo, la evolución de módulos, la coordinación con OnTheWeb y PixelPay, y todo lo que se requiere para garantizar la estabilidad del sistema que ustedes operan.', 1.000, '15', 1),
(85, 2, 1, 'Uso de Infraestructura de Google Cloud', 'Uso de Infraestructura de Google Cloud', 0.090, '15', 1),
(86, 2, 1, 'SOPORTE ALOJAMIENTO SERVIDOR VERCEL', 'SOPORTE ALOJAMIENTO SERVIDOR VERCEL', 800.000, '15', 1),
(87, 2, 27, 'Desarrollo Web Avanzado y Diseño Responsive', 'Desarrollo Web Avanzado y Diseño Responsive - Progrese.hn', 23000.000, '15', 1),
(88, 2, 27, 'Sistema de Consulta Financiera PROGRESE.', 'Sistema de Consulta Financiera PROGRESE. portal.progrese.hn', 115500.000, '15', 1),
(89, 2, 27, 'Módulo – Conexión con Banco Atlántida (Web Checkout)', 'Este módulo contempla la conexión con la pasarela de pagos de Banco Atlántida,\r\nbajo las mejores prácticas de seguridad y pruebas técnicas exigidas para la gestión de\r\npagos en línea.', 50000.000, '15', 1),
(90, 2, 28, 'Servicios Web Administrados y Soporte Operativo', 'Servicios Web Administrados y Soporte Operativo', 25000.000, '15', 1),
(91, 2, 4, 'Soporte Sitio Web', 'Soporte Sitio Web', 1.000, '15', 1),
(92, 2, 17, 'Generación de estrategia, Manejo de redes Sociales y todas las publicaciones, manejo de pauta y segmentacion, SEM, SEO y landing pages', 'Generación de estrategia, Manejo de redes Sociales y todas las publicaciones, manejo de pauta y segmentacion, SEM, SEO y landing pages', 14000.000, '15', 1),
(93, 2, 27, 'ESTRATEGIA DE MARKETING DIGITAL MARZO - ABRIL 2026', 'ESTRATEGIA DE MARKETING DIGITAL MARZO - ABRIL 2026', 19000.000, '15', 1),
(94, 2, 17, 'Soporte y Mantenimiento Técnico del CRM / Automatizaciones', 'Soporte y Mantenimiento Técnico del CRM / Automatizaciones', 4000.000, '15', 1),
(95, 2, 29, 'IMPRESIÓN DE STICKERS EN VINIL', 'IMPRESIÓN DE STICKERS EN VINIL', 5.870, '15', 1),
(96, 2, 29, 'CONSULTORIA CAMPAÑAS DE MARKETING', 'CONSULTORIA CAMPAÑAS DE MARKETING', 26000.000, '0', 1),
(97, 2, 30, 'CONTRATO PARA LA PRESTACIÓN DE SERVICIOS TÉCNICOS PUNTUALES', 'CONTRATO PARA LA PRESTACIÓN DE SERVICIOS TÉCNICOS PUNTUALES', 20250.000, '0', 1),
(98, 2, 27, 'Soporte y Mantenimiento para el Sitio Web', 'Soporte y Mantenimiento para el Sitio Web', 5000.000, '15', 1),
(99, 2, 27, 'Soporte y Mantenimiento para el Portal Financiero', 'Soporte y Mantenimiento para el Portal Financiero', 5000.000, '15', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `svg_path` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `proyecciones_cache`
--

CREATE TABLE `proyecciones_cache` (
  `cliente_id` int(11) NOT NULL,
  `anio` smallint(5) UNSIGNED NOT NULL,
  `mes` tinyint(3) UNSIGNED NOT NULL,
  `ing_contratos_estandar` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ing_contratos_periodicos` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ing_contratos_recibo` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ing_total_proyectado` decimal(14,2) NOT NULL DEFAULT 0.00,
  `egr_nomina` decimal(14,2) NOT NULL DEFAULT 0.00,
  `egr_gastos_fijos_prom` decimal(14,2) NOT NULL DEFAULT 0.00,
  `egr_gastos_var_prom` decimal(14,2) NOT NULL DEFAULT 0.00,
  `egr_total_proyectado` decimal(14,2) NOT NULL DEFAULT 0.00,
  `flujo_neto` decimal(14,2) NOT NULL DEFAULT 0.00,
  `alerta_nivel` enum('ok','atencion','critico') NOT NULL DEFAULT 'ok',
  `recomendacion` varchar(500) DEFAULT NULL,
  `generado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `proyecciones_cache`
--

INSERT INTO `proyecciones_cache` (`cliente_id`, `anio`, `mes`, `ing_contratos_estandar`, `ing_contratos_periodicos`, `ing_contratos_recibo`, `ing_total_proyectado`, `egr_nomina`, `egr_gastos_fijos_prom`, `egr_gastos_var_prom`, `egr_total_proyectado`, `flujo_neto`, `alerta_nivel`, `recomendacion`, `generado_en`) VALUES
(2, 2026, 3, 84891.30, 0.00, 46250.00, 131141.30, 46000.00, 2868.63, 0.00, 48868.63, 82272.67, 'ok', 'Flujo saludable. Margen del 62.7%.', '2026-03-30 16:06:11'),
(2, 2026, 4, 104891.30, 0.00, 46250.00, 151141.30, 46000.00, 2868.63, 0.00, 48868.63, 102272.67, 'ok', 'Flujo saludable. Margen del 67.7%.', '2026-03-30 16:06:11'),
(2, 2026, 5, 104891.30, 0.00, 26000.00, 130891.30, 46000.00, 2868.63, 0.00, 48868.63, 82022.67, 'ok', 'Flujo saludable. Margen del 62.7%.', '2026-03-30 16:06:11'),
(2, 2026, 6, 104891.30, 0.00, 26000.00, 130891.30, 46000.00, 2868.63, 0.00, 48868.63, 82022.67, 'ok', 'Flujo saludable. Margen del 62.7%.', '2026-03-30 16:06:11'),
(2, 2026, 7, 104891.30, 0.00, 26000.00, 130891.30, 46000.00, 2868.63, 0.00, 48868.63, 82022.67, 'ok', 'Flujo saludable. Margen del 62.7%.', '2026-03-30 16:06:11'),
(2, 2026, 8, 104891.30, 0.00, 26000.00, 130891.30, 46000.00, 2868.63, 0.00, 48868.63, 82022.67, 'ok', 'Flujo saludable. Margen del 62.7%.', '2026-03-30 16:06:11'),
(2, 2026, 9, 62500.00, 0.00, 0.00, 62500.00, 46000.00, 2868.63, 0.00, 48868.63, 13631.37, 'ok', 'Flujo saludable. Margen del 21.8%.', '2026-03-30 16:06:11'),
(2, 2026, 10, 52500.00, 0.00, 0.00, 52500.00, 46000.00, 2868.63, 0.00, 48868.63, 3631.37, 'atencion', 'Margen ajustado (6.9%). Evita gastos extraordinarios.', '2026-03-30 16:06:11'),
(2, 2026, 11, 52500.00, 0.00, 0.00, 52500.00, 46000.00, 2868.63, 0.00, 48868.63, 3631.37, 'atencion', 'Margen ajustado (6.9%). Evita gastos extraordinarios.', '2026-03-30 16:06:11'),
(2, 2026, 12, 52500.00, 0.00, 0.00, 52500.00, 46000.00, 2868.63, 0.00, 48868.63, 3631.37, 'atencion', 'Margen ajustado (6.9%). Evita gastos extraordinarios.', '2026-03-30 16:06:11'),
(2, 2027, 1, 27000.00, 0.00, 0.00, 27000.00, 46000.00, 2868.63, 0.00, 48868.63, -21868.63, 'critico', 'Flujo negativo de L 21,868.63. Revisar gastos fijos o añadir contratos.', '2026-03-30 16:06:11'),
(2, 2027, 2, 18000.00, 0.00, 0.00, 18000.00, 46000.00, 2868.63, 0.00, 48868.63, -30868.63, 'critico', 'Flujo negativo de L 30,868.63. Revisar gastos fijos o añadir contratos.', '2026-03-30 16:06:11');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `puntos_emision`
--

CREATE TABLE `puntos_emision` (
  `id` int(11) NOT NULL,
  `establecimiento_id` int(11) NOT NULL,
  `codigo_punto` varchar(10) NOT NULL,
  `descripcion` varchar(100) DEFAULT NULL,
  `departamento_id` int(11) DEFAULT NULL,
  `municipio_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `puntos_emision`
--

INSERT INTO `puntos_emision` (`id`, `establecimiento_id`, `codigo_punto`, `descripcion`, `departamento_id`, `municipio_id`) VALUES
(1, 1, '01', 'Siguatepeque', 3, 35),
(2, 2, '01', 'Punto central Puerto Cortés', 5, 64),
(3, 3, '01', 'Oficina La Lima', 5, 63),
(4, 4, '01', 'Sucursal Villanueva', 5, 65),
(5, 5, '01', 'Sucursal Choloma', 5, 62),
(7, 7, '01', 'Sucursal Tegucigalpa', 8, 107),
(8, 8, '01', 'Sucursal Comayagua', 3, 18),
(10, 10, '01', 'Sucursal Danlí', 7, 90);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id` tinyint(4) NOT NULL,
  `name` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id`, `name`) VALUES
(2, 'admin'),
(1, 'asesor'),
(3, 'superadmin');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tarjetas`
--

CREATE TABLE `tarjetas` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `banco` varchar(100) NOT NULL,
  `tipo` enum('visa','mastercard','amex','debito','credito','otro') NOT NULL DEFAULT 'visa',
  `ultimos_digitos` char(4) NOT NULL,
  `nombre_titular` varchar(150) DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `notas` varchar(300) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `tarjetas`
--

INSERT INTO `tarjetas` (`id`, `cliente_id`, `banco`, `tipo`, `ultimos_digitos`, `nombre_titular`, `activa`, `notas`, `creado_en`) VALUES
(1, 2, 'BANPAIS', 'visa', '6879', 'DANNY VELASQUEZ NARANJA Y MEDIA DEV', 1, NULL, '2026-03-30 10:36:45');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `nombre` varchar(100) NOT NULL,
  `correo` varchar(100) NOT NULL,
  `clave` varchar(255) NOT NULL,
  `rol` enum('superadmin','admin','facturador','lector') NOT NULL DEFAULT 'lector',
  `estado` enum('activo','inactivo') DEFAULT 'activo',
  `creado_en` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `cliente_id`, `nombre`, `correo`, `clave`, `rol`, `estado`, `creado_en`) VALUES
(4, NULL, 'Danny Velásquez', 'gerencia@naranjaymediahn.com', '$2y$10$PjcX6PzrfZEw3v9f/05mW.trJSlQqR7BhSgS5/eh0DTJO7ISKAqvq', 'superadmin', 'activo', '2025-06-20 17:01:48'),
(5, 2, 'Facturador Naranja', 'facturador@naranjaymediahn.com', '$2y$10$PjcX6PzrfZEw3v9f/05mW.trJSlQqR7BhSgS5/eh0DTJO7ISKAqvq', 'facturador', 'activo', '2025-06-20 17:01:48'),
(6, 2, 'Lector Naranja', 'lector@naranjaymediahn.com', '$2y$10$PjcX6PzrfZEw3v9f/05mW.trJSlQqR7BhSgS5/eh0DTJO7ISKAqvq', 'lector', 'activo', '2025-06-20 17:01:48'),
(7, 2, 'Administrador', 'administracion@naranjaymediahn.com', '$2y$10$PjcX6PzrfZEw3v9f/05mW.trJSlQqR7BhSgS5/eh0DTJO7ISKAqvq', 'admin', 'activo', '2025-07-03 13:43:49');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuario_establecimientos`
--

CREATE TABLE `usuario_establecimientos` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `establecimiento_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuario_establecimientos`
--

INSERT INTO `usuario_establecimientos` (`id`, `usuario_id`, `establecimiento_id`) VALUES
(1, 4, 1),
(2, 4, 2),
(5, 5, 1),
(6, 5, 2),
(8, 7, 1),
(9, 7, 2);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `bitacora_facturas`
--
ALTER TABLE `bitacora_facturas`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `cai_rangos`
--
ALTER TABLE `cai_rangos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cai_cliente` (`cliente_id`),
  ADD KEY `fk_cai_establecimiento` (`establecimiento_id`),
  ADD KEY `fk_cai_punto_emision` (`punto_emision_id`);

--
-- Indices de la tabla `categorias_gastos`
--
ALTER TABLE `categorias_gastos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cliente` (`cliente_id`);

--
-- Indices de la tabla `clientes_factura`
--
ALTER TABLE `clientes_factura`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`);

--
-- Indices de la tabla `clientes_saas`
--
ALTER TABLE `clientes_saas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subdominio` (`subdominio`);

--
-- Indices de la tabla `colaboradores`
--
ALTER TABLE `colaboradores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cliente` (`cliente_id`),
  ADD KEY `idx_activo` (`activo`),
  ADD KEY `idx_dpi` (`dpi`);

--
-- Indices de la tabla `colaborador_prestamos`
--
ALTER TABLE `colaborador_prestamos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_colaborador` (`colaborador_id`),
  ADD KEY `idx_cliente` (`cliente_id`),
  ADD KEY `idx_estado` (`estado`);

--
-- Indices de la tabla `colaborador_prestamo_cuotas`
--
ALTER TABLE `colaborador_prestamo_cuotas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_prestamo` (`prestamo_id`),
  ADD KEY `idx_colaborador` (`colaborador_id`),
  ADD KEY `idx_estado` (`estado`),
  ADD KEY `idx_fecha` (`fecha_esperada`);

--
-- Indices de la tabla `configuracion_sistema`
--
ALTER TABLE `configuracion_sistema`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `contratos`
--
ALTER TABLE `contratos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_contratos_receptor` (`receptor_id`),
  ADD KEY `idx_cliente_estado` (`cliente_id`,`estado`),
  ADD KEY `idx_fecha_fin` (`fecha_fin`),
  ADD KEY `fk_contratos_producto` (`producto_id`);

--
-- Indices de la tabla `contratos_clientes_rotativos`
--
ALTER TABLE `contratos_clientes_rotativos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_contrato_orden` (`contrato_id`,`orden`),
  ADD KEY `idx_receptor` (`receptor_id`);

--
-- Indices de la tabla `contratos_recibos`
--
ALTER TABLE `contratos_recibos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_numero_recibo` (`cliente_id`,`numero_recibo`),
  ADD KEY `idx_contrato_fecha` (`contrato_id`,`fecha_emision`),
  ADD KEY `idx_cliente_periodo` (`cliente_id`,`periodo_anio`,`periodo_mes`);

--
-- Indices de la tabla `contratos_recibos_contador`
--
ALTER TABLE `contratos_recibos_contador`
  ADD PRIMARY KEY (`cliente_id`,`anio`);

--
-- Indices de la tabla `contratos_servicios`
--
ALTER TABLE `contratos_servicios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contrato` (`contrato_id`),
  ADD KEY `idx_producto` (`producto_id`);

--
-- Indices de la tabla `departamentos`
--
ALTER TABLE `departamentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pais_id` (`pais_id`);

--
-- Indices de la tabla `detalle_productos`
--
ALTER TABLE `detalle_productos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `factura_id` (`factura_id`);

--
-- Indices de la tabla `establecimientos`
--
ALTER TABLE `establecimientos`
  ADD PRIMARY KEY (`establecimiento_id`),
  ADD KEY `cliente_id` (`cliente_id`);

--
-- Indices de la tabla `facturas`
--
ALTER TABLE `facturas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`),
  ADD KEY `cai_id` (`cai_id`),
  ADD KEY `receptor_id` (`receptor_id`),
  ADD KEY `fk_facturas_establecimiento` (`establecimiento_id`),
  ADD KEY `idx_contrato_id` (`contrato_id`),
  ADD KEY `idx_facturas_periodo` (`contrato_id`,`periodo_mes`,`periodo_anio`);

--
-- Indices de la tabla `factura_items`
--
ALTER TABLE `factura_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `factura_id` (`factura_id`),
  ADD KEY `producto_id` (`producto_id`);

--
-- Indices de la tabla `factura_items_receptor`
--
ALTER TABLE `factura_items_receptor`
  ADD PRIMARY KEY (`id`),
  ADD KEY `factura_id` (`factura_id`),
  ADD KEY `producto_id` (`producto_id`);

--
-- Indices de la tabla `gastos`
--
ALTER TABLE `gastos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cliente` (`cliente_id`),
  ADD KEY `idx_categoria` (`categoria_id`),
  ADD KEY `idx_fecha` (`fecha`),
  ADD KEY `idx_estado` (`estado`),
  ADD KEY `idx_grupo` (`gasto_grupo_id`),
  ADD KEY `idx_vencimiento` (`cliente_id`,`fecha_vencimiento`);

--
-- Indices de la tabla `municipios`
--
ALTER TABLE `municipios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_municipios_departamento` (`departamento_id`);

--
-- Indices de la tabla `paises`
--
ALTER TABLE `paises`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `precios_especiales`
--
ALTER TABLE `precios_especiales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`),
  ADD KEY `producto_id` (`producto_id`);

--
-- Indices de la tabla `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`);

--
-- Indices de la tabla `productos_clientes`
--
ALTER TABLE `productos_clientes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`),
  ADD KEY `receptores_id` (`receptores_id`);

--
-- Indices de la tabla `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `proyecciones_cache`
--
ALTER TABLE `proyecciones_cache`
  ADD PRIMARY KEY (`cliente_id`,`anio`,`mes`);

--
-- Indices de la tabla `puntos_emision`
--
ALTER TABLE `puntos_emision`
  ADD PRIMARY KEY (`id`),
  ADD KEY `establecimiento_id` (`establecimiento_id`),
  ADD KEY `fk_puntos_departamento` (`departamento_id`),
  ADD KEY `fk_puntos_municipio` (`municipio_id`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indices de la tabla `tarjetas`
--
ALTER TABLE `tarjetas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cliente` (`cliente_id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `correo` (`correo`),
  ADD KEY `cliente_id` (`cliente_id`);

--
-- Indices de la tabla `usuario_establecimientos`
--
ALTER TABLE `usuario_establecimientos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario_id` (`usuario_id`,`establecimiento_id`),
  ADD KEY `establecimiento_id` (`establecimiento_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `bitacora_facturas`
--
ALTER TABLE `bitacora_facturas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=337;

--
-- AUTO_INCREMENT de la tabla `cai_rangos`
--
ALTER TABLE `cai_rangos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `categorias_gastos`
--
ALTER TABLE `categorias_gastos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT de la tabla `clientes_factura`
--
ALTER TABLE `clientes_factura`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT de la tabla `clientes_saas`
--
ALTER TABLE `clientes_saas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `colaboradores`
--
ALTER TABLE `colaboradores`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `colaborador_prestamos`
--
ALTER TABLE `colaborador_prestamos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT de la tabla `colaborador_prestamo_cuotas`
--
ALTER TABLE `colaborador_prestamo_cuotas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=187;

--
-- AUTO_INCREMENT de la tabla `configuracion_sistema`
--
ALTER TABLE `configuracion_sistema`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `contratos`
--
ALTER TABLE `contratos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT de la tabla `contratos_clientes_rotativos`
--
ALTER TABLE `contratos_clientes_rotativos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT de la tabla `contratos_recibos`
--
ALTER TABLE `contratos_recibos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `contratos_servicios`
--
ALTER TABLE `contratos_servicios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT de la tabla `departamentos`
--
ALTER TABLE `departamentos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT de la tabla `detalle_productos`
--
ALTER TABLE `detalle_productos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `establecimientos`
--
ALTER TABLE `establecimientos`
  MODIFY `establecimiento_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT de la tabla `facturas`
--
ALTER TABLE `facturas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=196;

--
-- AUTO_INCREMENT de la tabla `factura_items`
--
ALTER TABLE `factura_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=214;

--
-- AUTO_INCREMENT de la tabla `factura_items_receptor`
--
ALTER TABLE `factura_items_receptor`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1064;

--
-- AUTO_INCREMENT de la tabla `gastos`
--
ALTER TABLE `gastos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=234;

--
-- AUTO_INCREMENT de la tabla `municipios`
--
ALTER TABLE `municipios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=297;

--
-- AUTO_INCREMENT de la tabla `paises`
--
ALTER TABLE `paises`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `precios_especiales`
--
ALTER TABLE `precios_especiales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `productos`
--
ALTER TABLE `productos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT de la tabla `productos_clientes`
--
ALTER TABLE `productos_clientes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- AUTO_INCREMENT de la tabla `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `puntos_emision`
--
ALTER TABLE `puntos_emision`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `tarjetas`
--
ALTER TABLE `tarjetas`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `usuario_establecimientos`
--
ALTER TABLE `usuario_establecimientos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `cai_rangos`
--
ALTER TABLE `cai_rangos`
  ADD CONSTRAINT `cai_rangos_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes_saas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cai_rangos_ibfk_2` FOREIGN KEY (`establecimiento_id`) REFERENCES `establecimientos` (`establecimiento_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cai_rangos_ibfk_3` FOREIGN KEY (`punto_emision_id`) REFERENCES `establecimientos` (`establecimiento_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cai_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes_saas` (`id`),
  ADD CONSTRAINT `fk_cai_establecimiento` FOREIGN KEY (`establecimiento_id`) REFERENCES `establecimientos` (`establecimiento_id`),
  ADD CONSTRAINT `fk_cai_punto_emision` FOREIGN KEY (`punto_emision_id`) REFERENCES `establecimientos` (`establecimiento_id`);

--
-- Filtros para la tabla `clientes_factura`
--
ALTER TABLE `clientes_factura`
  ADD CONSTRAINT `clientes_factura_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes_saas` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `colaborador_prestamo_cuotas`
--
ALTER TABLE `colaborador_prestamo_cuotas`
  ADD CONSTRAINT `colaborador_prestamo_cuotas_ibfk_1` FOREIGN KEY (`prestamo_id`) REFERENCES `colaborador_prestamos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `contratos`
--
ALTER TABLE `contratos`
  ADD CONSTRAINT `fk_contratos_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes_saas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_contratos_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos_clientes` (`id`),
  ADD CONSTRAINT `fk_contratos_receptor` FOREIGN KEY (`receptor_id`) REFERENCES `clientes_factura` (`id`);

--
-- Filtros para la tabla `departamentos`
--
ALTER TABLE `departamentos`
  ADD CONSTRAINT `departamentos_ibfk_1` FOREIGN KEY (`pais_id`) REFERENCES `paises` (`id`);

--
-- Filtros para la tabla `detalle_productos`
--
ALTER TABLE `detalle_productos`
  ADD CONSTRAINT `detalle_productos_ibfk_1` FOREIGN KEY (`factura_id`) REFERENCES `facturas` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `establecimientos`
--
ALTER TABLE `establecimientos`
  ADD CONSTRAINT `establecimientos_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes_saas` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `facturas`
--
ALTER TABLE `facturas`
  ADD CONSTRAINT `facturas_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes_saas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `facturas_ibfk_2` FOREIGN KEY (`cai_id`) REFERENCES `cai_rangos` (`id`),
  ADD CONSTRAINT `facturas_ibfk_3` FOREIGN KEY (`receptor_id`) REFERENCES `clientes_factura` (`id`),
  ADD CONSTRAINT `fk_facturas_contrato` FOREIGN KEY (`contrato_id`) REFERENCES `contratos` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_facturas_establecimiento` FOREIGN KEY (`establecimiento_id`) REFERENCES `establecimientos` (`establecimiento_id`);

--
-- Filtros para la tabla `factura_items`
--
ALTER TABLE `factura_items`
  ADD CONSTRAINT `factura_items_ibfk_1` FOREIGN KEY (`factura_id`) REFERENCES `facturas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `factura_items_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`);

--
-- Filtros para la tabla `factura_items_receptor`
--
ALTER TABLE `factura_items_receptor`
  ADD CONSTRAINT `factura_items_receptor_ibfk_1` FOREIGN KEY (`factura_id`) REFERENCES `facturas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `factura_items_receptor_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos_clientes` (`id`);

--
-- Filtros para la tabla `municipios`
--
ALTER TABLE `municipios`
  ADD CONSTRAINT `fk_municipios_departamento` FOREIGN KEY (`departamento_id`) REFERENCES `departamentos` (`id`),
  ADD CONSTRAINT `municipios_ibfk_1` FOREIGN KEY (`departamento_id`) REFERENCES `departamentos` (`id`);

--
-- Filtros para la tabla `precios_especiales`
--
ALTER TABLE `precios_especiales`
  ADD CONSTRAINT `precios_especiales_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes_saas` (`id`),
  ADD CONSTRAINT `precios_especiales_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`);

--
-- Filtros para la tabla `productos`
--
ALTER TABLE `productos`
  ADD CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes_saas` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `productos_clientes`
--
ALTER TABLE `productos_clientes`
  ADD CONSTRAINT `productos_clientes_fk_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes_saas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `productos_clientes_fk_receptor` FOREIGN KEY (`receptores_id`) REFERENCES `clientes_factura` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `puntos_emision`
--
ALTER TABLE `puntos_emision`
  ADD CONSTRAINT `fk_puntos_departamento` FOREIGN KEY (`departamento_id`) REFERENCES `departamentos` (`id`),
  ADD CONSTRAINT `fk_puntos_municipio` FOREIGN KEY (`municipio_id`) REFERENCES `municipios` (`id`),
  ADD CONSTRAINT `puntos_emision_ibfk_1` FOREIGN KEY (`establecimiento_id`) REFERENCES `establecimientos` (`establecimiento_id`);

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes_saas` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `usuario_establecimientos`
--
ALTER TABLE `usuario_establecimientos`
  ADD CONSTRAINT `usuario_establecimientos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  ADD CONSTRAINT `usuario_establecimientos_ibfk_2` FOREIGN KEY (`establecimiento_id`) REFERENCES `establecimientos` (`establecimiento_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
