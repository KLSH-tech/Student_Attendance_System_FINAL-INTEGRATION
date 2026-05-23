-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 25, 2026 at 03:50 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `student_attendance_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `status` enum('Present','Late','Absent') NOT NULL DEFAULT 'Present',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_logs`
--

CREATE TABLE `attendance_logs` (
  `log_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `student_number` varchar(20) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `scanned_by` enum('teacher','student') DEFAULT 'student',
  `action` enum('in','out') DEFAULT 'in',
  `attendance_status` enum('on_time','late','absent') DEFAULT 'on_time',
  `notification_sent` tinyint(1) DEFAULT 0,
  `sms_sent` tinyint(1) DEFAULT 0,
  `logged_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_logs`
--

INSERT INTO `attendance_logs` (`log_id`, `student_id`, `student_number`, `class_id`, `schedule_id`, `scanned_by`, `action`, `attendance_status`, `notification_sent`, `sms_sent`, `logged_at`) VALUES
(1, 2, '20250249', 1, 8, '', 'in', '', 0, 1, '2026-05-25 09:35:32'),
(2, 4, '20250279', 1, 8, '', 'in', '', 0, 1, '2026-05-25 09:37:36'),
(3, 4, '20250279', 1, 8, '', 'out', '', 0, 0, '2026-05-25 09:37:51'),
(4, 3, '20250815', 1, 8, '', 'in', '', 0, 1, '2026-05-25 09:38:13'),
(5, 3, '20250815', 1, 8, '', 'out', '', 0, 0, '2026-05-25 09:38:13'),
(6, 1, 'B2017586', 1, 8, '', 'in', '', 0, 1, '2026-05-25 09:38:20'),
(7, 1, 'B2017586', 1, 8, '', 'out', '', 0, 0, '2026-05-25 09:38:27'),
(8, 2, '20250249', 1, 8, '', 'out', '', 0, 0, '2026-05-25 09:58:31'),
(9, 2, '20250249', 1, 8, '', 'in', '', 0, 1, '2026-05-25 09:59:05'),
(10, 2, '20250249', 1, 8, '', 'out', '', 0, 0, '2026-05-25 10:02:48'),
(11, 2, '20250249', 1, 8, '', 'in', '', 0, 1, '2026-05-25 10:03:22'),
(12, 2, '20250249', 1, 8, '', 'out', '', 0, 0, '2026-05-25 09:30:40'),
(13, 2, '20250249', 1, 8, '', 'in', '', 0, 1, '2026-05-25 09:31:22'),
(14, 2, '20250249', 1, 8, '', 'out', '', 0, 0, '2026-05-25 10:04:54'),
(15, 3, '20250815', 1, 8, '', 'in', '', 0, 1, '2026-05-25 09:41:45'),
(16, 3, '20250815', 1, 8, '', 'out', '', 0, 0, '2026-05-25 09:43:23'),
(17, 1, 'B2017586', 1, 8, '', 'in', '', 0, 1, '2026-05-25 09:43:27');

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `class_id` int(11) NOT NULL,
  `class_code` varchar(10) DEFAULT NULL,
  `course_code` varchar(20) NOT NULL,
  `section` varchar(20) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `time_in` time DEFAULT NULL,
  `grace_period_minutes` int(11) DEFAULT 15
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`class_id`, `class_code`, `course_code`, `section`, `teacher_id`, `time_in`, `grace_period_minutes`) VALUES
(1, 'A4', 'IT11', 'BSIT 1A', 16, NULL, 15),
(2, 'A37', 'ITC15', 'BSIT 2B', 16, NULL, 15),
(3, 'A28', 'ELECIT103', 'BSIT 2A', 17, NULL, 15),
(4, 'A30', 'IT16', 'BSIT 2A', 18, NULL, 15),
(5, 'A51', 'IT22', 'BSIT 3A', 17, NULL, 15);

-- --------------------------------------------------------

--
-- Table structure for table `dispute_requests`
--

CREATE TABLE `dispute_requests` (
  `dispute_id` int(11) NOT NULL,
  `attendance_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('Pending','Under Review','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_history`
--

CREATE TABLE `message_history` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `parent_contact` varchar(20) DEFAULT NULL,
  `message_body` text DEFAULT NULL,
  `attendance_status` enum('on_time','late') DEFAULT 'on_time',
  `status` varchar(20) DEFAULT 'failed',
  `api_response` text DEFAULT NULL,
  `log_id` int(11) DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parents`
--

CREATE TABLE `parents` (
  `parent_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `parent_name` varchar(100) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `relationship` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `parents`
--

INSERT INTO `parents` (`parent_id`, `student_id`, `parent_name`, `contact_number`, `relationship`) VALUES
(2, 1, '', '09508760485', 'Guardian'),
(3, 2, '', '09508760485', 'Guardian'),
(4, 3, '', '09508760485', 'Guardian'),
(5, 4, '', '09508760485', 'Guardian'),
(6, 5, '', '09508760485', 'Guardian'),
(7, 7, '', '09508760485', 'Guardian'),
(8, 8, '', '09508760485', 'Guardian'),
(9, 9, '', '09508760485', 'Guardian'),
(10, 10, '', '09508760485', 'Guardian'),
(11, 11, '', '09508760485', 'Guardian'),
(12, 12, '', '09508760485', 'Guardian'),
(13, 13, '', '09508760485', 'Guardian'),
(14, 14, '', '09508760485', 'Guardian'),
(15, 15, '', '09508760485', 'Guardian'),
(16, 16, '', '09508760485', 'Guardian'),
(17, 17, '', '09508760485', 'Guardian'),
(18, 18, '', '09508760485', 'Guardian'),
(19, 19, '', '09508760485', 'Guardian'),
(20, 20, '', '09508760485', 'Guardian'),
(21, 21, '', '09508760485', 'Guardian'),
(22, 22, '', '09508760485', 'Guardian'),
(23, 23, '', '09508760485', 'Guardian'),
(24, 24, '', '09508760485', 'Guardian'),
(25, 25, '', '09508760485', 'Guardian'),
(26, 26, '', '09508760485', 'Guardian'),
(27, 27, '', '09508760485', 'Guardian'),
(28, 28, '', '09508760485', 'Guardian'),
(29, 29, '', '09508760485', 'Guardian'),
(30, 30, '', '09508760485', 'Guardian'),
(31, 31, '', '09508760485', 'Guardian'),
(32, 32, '', '09508760485', 'Guardian'),
(33, 33, '', '09508760485', 'Guardian'),
(34, 34, '', '09508760485', 'Guardian'),
(35, 35, '', '09508760485', 'Guardian'),
(36, 36, '', '09508760485', 'Guardian'),
(37, 37, '', '09508760485', 'Guardian'),
(38, 38, '', '09508760485', 'Guardian'),
(39, 39, '', '09508760485', 'Guardian'),
(40, 40, '', '09508760485', 'Guardian'),
(41, 41, '', '09508760485', 'Guardian'),
(42, 42, '', '09508760485', 'Guardian'),
(43, 43, '', '09508760485', 'Guardian'),
(44, 44, '', '09508760485', 'Guardian'),
(45, 45, '', '09508760485', 'Guardian'),
(46, 46, '', '09508760485', 'Guardian'),
(47, 47, '', '09508760485', 'Guardian'),
(48, 48, '', '09508760485', 'Guardian'),
(49, 49, '', '09508760485', 'Guardian'),
(50, 50, '', '09508760485', 'Guardian'),
(51, 51, '', '09508760485', 'Guardian'),
(52, 52, '', '09508760485', 'Guardian'),
(53, 53, '', '09508760485', 'Guardian'),
(54, 54, '', '09508760485', 'Guardian'),
(55, 55, '', '09508760485', 'Guardian'),
(56, 56, '', '09508760485', 'Guardian'),
(57, 57, '', '09508760485', 'Guardian'),
(58, 58, '', '09508760485', 'Guardian'),
(59, 59, '', '09508760485', 'Guardian'),
(60, 60, '', '09508760485', 'Guardian'),
(61, 61, '', '09508760485', 'Guardian'),
(62, 62, '', '09508760485', 'Guardian'),
(63, 63, '', '09508760485', 'Guardian');

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `schedule_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `day` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room` varchar(20) DEFAULT NULL,
  `in_records` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=visible in Records/Profile Mgmt (Cayao)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedules`
--

INSERT INTO `schedules` (`schedule_id`, `class_id`, `day`, `start_time`, `end_time`, `room`, `in_records`) VALUES
(7, 2, 'Monday', '08:00:00', '09:30:00', 'COM LAB A', 1),
(8, 1, 'Monday', '09:30:00', '10:30:00', 'COM LAB A', 1),
(9, 5, 'Monday', '10:30:00', '12:00:00', 'COM LAB A', 1),
(10, 3, 'Monday', '13:00:00', '14:30:00', 'COM LAB A', 1),
(11, 3, 'Monday', '14:30:00', '16:30:00', 'COM LAB A', 1),
(12, 4, 'Monday', '17:00:00', '18:30:00', 'COM LAB A', 1);

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `student_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `course` varchar(20) DEFAULT NULL,
  `year_level` int(11) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `user_id`, `student_number`, `full_name`, `gender`, `course`, `year_level`, `section`, `contact`, `email`, `created_at`) VALUES
(1, NULL, 'B2017586', 'Alsado, Kean Rose M.', 'F', 'BSIT', 1, 'BSIT 1A', '09691082153', NULL, '2026-05-21 07:41:43'),
(2, NULL, '20250249', 'Arriola, Adrian James T.', 'M', 'BSIT', 1, 'BSIT 1A', '09564038676', 'Vasel.arriola2002@gmail.com', '2026-05-21 07:41:43'),
(3, NULL, '20250815', 'Barinos, John Homer N.', 'M', 'BSIT', 1, 'BSIT 1A', '06956227343', NULL, '2026-05-21 07:41:43'),
(4, NULL, '20250279', 'Diosoy, Vincent Jay S.', 'M', 'BSIT', 1, 'BSIT 1A', '09663548543', NULL, '2026-05-21 07:41:43'),
(5, NULL, '20250508', 'Encaja, Karl Timothy E.', 'M', 'BSIT', 1, 'BSIT 1A', '09318254576', NULL, '2026-05-21 07:41:43'),
(7, NULL, '20250634', 'Garsula, Polo James A.', 'M', 'BSIT', 1, 'BSIT 1A', '09078743241', NULL, '2026-05-21 07:41:43'),
(8, NULL, '20240297', 'Germo, Oya S.', 'M', 'BSIT', 1, 'BSIT 1A', '09912693704', NULL, '2026-05-21 07:41:43'),
(9, NULL, '20250813', 'Gonzales, Chris John S.', 'F', 'BSIT', 1, 'BSIT 1A', '09121381030', NULL, '2026-05-21 07:41:43'),
(10, NULL, 'B20240540', 'Grande, Althea Kassandra A.', 'F', 'BSIT', 1, 'BSIT 1A', '09453816399', 'althea.grande.s@southlandcollege.edu.ph', '2026-05-21 07:41:43'),
(11, NULL, '20250820', 'Alfuente, Marvin Neil M.', 'M', 'BSIT', 2, 'BSIT 2B', NULL, NULL, '2026-05-21 07:41:43'),
(12, NULL, '20240414', 'Arimas, John Paul T.', 'M', 'BSIT', 2, 'BSIT 2B', '09956214032', NULL, '2026-05-21 07:41:43'),
(13, NULL, 'B20230123', 'Calalas, Loranz Ali A.', 'M', 'BSIT', 2, 'BSIT 2B', '09183219440', NULL, '2026-05-21 07:41:43'),
(14, NULL, '20240516', 'Francia Jr., Edgar B.', 'M', 'BSIT', 2, 'BSIT 2B', '09850803845', NULL, '2026-05-21 07:41:43'),
(15, NULL, '20250857', 'Gagatam, Denny C.', 'F', 'BSIT', 2, 'BSIT 2B', '09657923669', NULL, '2026-05-21 07:41:43'),
(16, NULL, '20240478', 'Hsu, Cheng Huang R.', 'M', 'BSIT', 2, 'BSIT 2B', '09158763035', NULL, '2026-05-21 07:41:43'),
(17, NULL, '20240227', 'Hulguin, John Paul E.', 'M', 'BSIT', 2, 'BSIT 2B', '09157119687', NULL, '2026-05-21 07:41:43'),
(18, NULL, '20240415', 'Lopez, Lyzander Alexius C.', 'M', 'BSIT', 2, 'BSIT 2B', '09761597013', NULL, '2026-05-21 07:41:43'),
(19, NULL, '20250658', 'Martir, Kent Jonil P.', 'M', 'BSIT', 2, 'BSIT 2B', '09567113247', NULL, '2026-05-21 07:41:43'),
(20, NULL, '20240176', 'Molarto, Ivan Clint D.', 'M', 'BSIT', 2, 'BSIT 2B', '09701978001', NULL, '2026-05-21 07:41:43'),
(21, NULL, '20240140', 'Putong, Reman Jr. F.', 'M', 'BSIT', 2, 'BSIT 2B', '09087062227', NULL, '2026-05-21 07:41:43'),
(22, NULL, '20240147', 'Quillo, Skitch G.', 'M', 'BSIT', 2, 'BSIT 2B', '09127831714', NULL, '2026-05-21 07:41:43'),
(23, NULL, '20240186', 'Servano, Eunice Pearl E.', 'F', 'BSIT', 2, 'BSIT 2B', '09064538148', NULL, '2026-05-21 07:41:43'),
(24, NULL, '20220187', 'Tabacolde, May Chelle A.', 'F', 'BSIT', 2, 'BSIT 2B', '09272356140', NULL, '2026-05-21 07:41:43'),
(25, NULL, '20240260', 'Tayco, Elderie John C.', 'M', 'BSIT', 2, 'BSIT 2B', '09673240757', NULL, '2026-05-21 07:41:43'),
(26, NULL, '20240278', 'Zulueta, Cj J.', 'M', 'BSIT', 2, 'BSIT 2B', '09461521354', NULL, '2026-05-21 07:41:43'),
(27, NULL, '20220003', 'Abella, Mark Allexi T.', 'M', 'BSIT', 2, 'BSIT 2A', '09777556450', NULL, '2026-05-21 07:41:43'),
(28, NULL, '20240295', 'Aquino, Zurich Clyde O.', 'M', 'BSIT', 2, 'BSIT 2A', '09601038624', 'zurichclyde.aquino.s@southlandcollege.edu.ph', '2026-05-21 07:41:43'),
(29, NULL, '20240225', 'Bagaporo, Alexa P.', 'F', 'BSIT', 2, 'BSIT 2A', '09936412673', NULL, '2026-05-21 07:41:43'),
(30, NULL, '20240253', 'Bendol, Reynalie T.', 'F', 'BSIT', 2, 'BSIT 2A', '09673225253', NULL, '2026-05-21 07:41:43'),
(31, NULL, '20210365', 'Bruno, Robert Dave B.', 'M', 'BSIT', 2, 'BSIT 2A', '09162199005', NULL, '2026-05-21 07:41:43'),
(32, NULL, '20240234', 'Castillo JR., Alfred John S.', 'M', 'BSIT', 2, 'BSIT 2A', NULL, NULL, '2026-05-21 07:41:43'),
(33, NULL, '20250699', 'Cuison, Patrick Reniel D.', 'M', 'BSIT', 3, 'BSIT 2A', '09628087554', NULL, '2026-05-21 07:41:43'),
(34, NULL, '20240044', 'David, Val Zendrick C.', 'M', 'BSIT', 2, 'BSIT 2A', '09070042451', NULL, '2026-05-21 07:41:43'),
(35, NULL, '20240208', 'De Leon, Nicca A.', 'F', 'BSIT', 2, 'BSIT 2A', '09933889247', NULL, '2026-05-21 07:41:43'),
(36, NULL, '20240166', 'Eguis, Crisly Joy G.', 'F', 'BSIT', 2, 'BSIT 2A', '09512266875', NULL, '2026-05-21 07:41:43'),
(37, NULL, '20240296', 'Fordan, Bless Joy N.', 'F', 'BSIT', 2, 'BSIT 2A', NULL, NULL, '2026-05-21 07:41:43'),
(38, NULL, '20220331', 'Guillepa, Sam C.', 'M', 'BSIT', 2, 'BSIT 2A', '09919085250', 'sam.guillepa.s@southlandcollege.edu.ph', '2026-05-21 07:41:43'),
(39, NULL, '20220240', 'Jaranilla, Jardi John D.', 'M', 'BSIT', 2, 'BSIT 2A', '09127067766', NULL, '2026-05-21 07:41:43'),
(40, NULL, '20220208', 'Lozada, John Paul R.', 'M', 'BSIT', 2, 'BSIT 2A', '09468105751', NULL, '2026-05-21 07:41:43'),
(41, NULL, '20220186', 'Mancia, Mark D.', 'M', 'BSIT', 2, 'BSIT 2A', '09942098148', NULL, '2026-05-21 07:41:43'),
(42, NULL, '20240142', 'Ordas, Lawrence F.', 'M', 'BSIT', 2, 'BSIT 2A', '09919066864', NULL, '2026-05-21 07:41:43'),
(43, NULL, '20240093', 'Panila, Michaellah Luisa S.', 'F', 'BSIT', 2, 'BSIT 2A', '09667380079', 'michaelaluisa.panila.s@southlandcollege.edu.ph', '2026-05-21 07:41:43'),
(44, NULL, '20210359', 'Sansano, Prince Louie G.', 'M', 'BSIT', 2, 'BSIT 2A', '09939436638', NULL, '2026-05-21 07:41:43'),
(45, NULL, '20220174', 'Sevilleno, Julianne Grace Frances A.', 'F', 'BSIT', 2, 'BSIT 2A', '09295598035', NULL, '2026-05-21 07:41:43'),
(46, NULL, '20220316', 'Sumugat, Christy Joyce T.', 'F', 'BSIT', 2, 'BSIT 2A', '09494577129', 'christyjoyce.sumugat.s@southlandcollege.edu.ph', '2026-05-21 07:41:43'),
(47, NULL, '20240190', 'Susada, Sharah Mae C.', 'F', 'BSIT', 2, 'BSIT 2A', '09912693660', NULL, '2026-05-21 07:41:43'),
(48, NULL, '20240315', 'De Jesus, Zenneth Braven P.', 'M', 'BSIT', 2, 'BSIT 2A', '09927265257', NULL, '2026-05-21 07:41:43'),
(49, NULL, '20240519', 'Garduce, Rey Benedict', 'M', 'BSIT', 2, 'BSIT 2A', NULL, NULL, '2026-05-21 07:41:43'),
(50, NULL, '20250738', 'Galan, Jan Mark S.', 'M', 'BSIT', 1, 'BSIT 1A', '09637570257', NULL, '2026-05-21 07:41:43'),
(51, NULL, '20250570', 'Lomocso, Adrian T.', 'M', 'BSIT', 1, 'BSIT 1A', '09924354591', NULL, '2026-05-21 07:41:43'),
(52, NULL, '20250080', 'Luston, Jaymar I.', 'M', 'BSIT', 1, 'BSIT 1A', '09948597337', NULL, '2026-05-21 07:41:43'),
(53, NULL, '20250540', 'Maquiling, Ric Joseph S.', 'M', 'BSIT', 1, 'BSIT 1A', '09658777110', NULL, '2026-05-21 07:41:43'),
(54, NULL, '20250534', 'Martir, Lander Keith T.', 'M', 'BSIT', 1, 'BSIT 1A', '09218690856', NULL, '2026-05-21 07:41:43'),
(55, NULL, '20250483', 'Mejorada, Kyle Mathew G.', 'M', 'BSIT', 1, 'BSIT 1A', '09150856173', NULL, '2026-05-21 07:41:43'),
(56, NULL, '20250119', 'Padriquel, Xian Daniel F.', 'M', 'BSIT', 1, 'BSIT 1A', '09850763067', NULL, '2026-05-21 07:41:43'),
(57, NULL, '20250576', 'Pandis, Rodeliza C.', 'F', 'BSIT', 1, 'BSIT 1A', '09544421427', NULL, '2026-05-21 07:41:43'),
(58, NULL, '20250633', 'Rodilla, Ken Angelo R.', 'M', 'BSIT', 1, 'BSIT 1A', '09383762869', NULL, '2026-05-21 07:41:43'),
(59, NULL, '20250532', 'Rufin, Jan Rodmel M.', 'M', 'BSIT', 1, 'BSIT 1A', '09308586700', NULL, '2026-05-21 07:41:43'),
(60, NULL, 'B20230185', 'Sebala, Jason R.', 'M', 'BSIT', 1, 'BSIT 1A', '09396143600', NULL, '2026-05-21 07:41:43'),
(61, NULL, '20250588', 'Sian, Lilian Paula Marie T.', 'F', 'BSIT', 1, 'BSIT 1A', '09167393236', NULL, '2026-05-21 07:41:43'),
(62, NULL, '20250497', 'Tamon, John Jacob B.', 'M', 'BSIT', 1, 'BSIT 1A', '09561665575', NULL, '2026-05-21 07:41:43'),
(63, NULL, '20250798', 'Villanueva, Junila Althea H.', 'F', 'BSIT', 1, 'BSIT 1A', '09052417483', NULL, '2026-05-21 07:41:43');

-- --------------------------------------------------------

--
-- Table structure for table `student_schedule`
--

CREATE TABLE `student_schedule` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_schedule`
--

INSERT INTO `student_schedule` (`id`, `student_id`, `schedule_id`, `class_id`, `created_at`) VALUES
(1, 1, 8, 1, '2026-04-14 07:42:37'),
(2, 2, 8, 1, '2026-04-14 07:42:37'),
(3, 3, 8, 1, '2026-04-14 07:42:37'),
(4, 4, 8, 1, '2026-04-14 07:42:37'),
(5, 5, 8, 1, '2026-04-14 07:42:37'),
(6, 7, 8, 1, '2026-04-14 07:42:37'),
(7, 8, 8, 1, '2026-04-14 07:42:37'),
(8, 9, 8, 1, '2026-04-14 07:42:37'),
(9, 10, 8, 1, '2026-04-14 07:42:37'),
(10, 50, 8, 1, '2026-04-14 07:42:37'),
(11, 51, 8, 1, '2026-04-14 07:42:37'),
(12, 52, 8, 1, '2026-04-14 07:42:37'),
(13, 53, 8, 1, '2026-04-14 07:42:37'),
(14, 54, 8, 1, '2026-04-14 07:42:37'),
(15, 55, 8, 1, '2026-04-14 07:42:37'),
(16, 56, 8, 1, '2026-04-14 07:42:37'),
(17, 57, 8, 1, '2026-04-14 07:42:37'),
(18, 58, 8, 1, '2026-04-14 07:42:37'),
(19, 59, 8, 1, '2026-04-14 07:42:37'),
(20, 60, 8, 1, '2026-04-14 07:42:37'),
(21, 61, 8, 1, '2026-04-14 07:42:37'),
(22, 62, 8, 1, '2026-04-14 07:42:37'),
(23, 63, 8, 1, '2026-04-14 07:42:37'),
(32, 11, 7, 2, '2026-04-14 07:42:37'),
(33, 12, 7, 2, '2026-04-14 07:42:37'),
(34, 13, 7, 2, '2026-04-14 07:42:37'),
(35, 14, 7, 2, '2026-04-14 07:42:37'),
(36, 15, 7, 2, '2026-04-14 07:42:37'),
(37, 16, 7, 2, '2026-04-14 07:42:37'),
(38, 17, 7, 2, '2026-04-14 07:42:37'),
(39, 18, 7, 2, '2026-04-14 07:42:37'),
(40, 19, 7, 2, '2026-04-14 07:42:37'),
(41, 20, 7, 2, '2026-04-14 07:42:37'),
(42, 21, 7, 2, '2026-04-14 07:42:37'),
(43, 22, 7, 2, '2026-04-14 07:42:37'),
(44, 23, 7, 2, '2026-04-14 07:42:37'),
(45, 24, 7, 2, '2026-04-14 07:42:37'),
(46, 25, 7, 2, '2026-04-14 07:42:37'),
(47, 26, 7, 2, '2026-04-14 07:42:37'),
(63, 27, 10, 3, '2026-04-14 07:42:37'),
(64, 28, 10, 3, '2026-04-14 07:42:37'),
(65, 29, 10, 3, '2026-04-14 07:42:37'),
(66, 30, 10, 3, '2026-04-14 07:42:37'),
(67, 31, 10, 3, '2026-04-14 07:42:37'),
(68, 32, 10, 3, '2026-04-14 07:42:37'),
(69, 33, 10, 3, '2026-04-14 07:42:37'),
(70, 34, 10, 3, '2026-04-14 07:42:37'),
(71, 35, 10, 3, '2026-04-14 07:42:37'),
(72, 36, 10, 3, '2026-04-14 07:42:37'),
(73, 37, 10, 3, '2026-04-14 07:42:37'),
(74, 38, 10, 3, '2026-04-14 07:42:37'),
(75, 39, 10, 3, '2026-04-14 07:42:37'),
(76, 40, 10, 3, '2026-04-14 07:42:37'),
(77, 41, 10, 3, '2026-04-14 07:42:37'),
(78, 42, 10, 3, '2026-04-14 07:42:37'),
(79, 43, 10, 3, '2026-04-14 07:42:37'),
(80, 44, 10, 3, '2026-04-14 07:42:37'),
(81, 45, 10, 3, '2026-04-14 07:42:37'),
(82, 46, 10, 3, '2026-04-14 07:42:37'),
(83, 47, 10, 3, '2026-04-14 07:42:37'),
(94, 8, 12, 4, '2026-04-14 07:42:37'),
(95, 27, 12, 4, '2026-04-14 07:42:37'),
(96, 28, 12, 4, '2026-04-14 07:42:37'),
(97, 29, 12, 4, '2026-04-14 07:42:37'),
(98, 30, 12, 4, '2026-04-14 07:42:37'),
(99, 31, 12, 4, '2026-04-14 07:42:37'),
(100, 32, 12, 4, '2026-04-14 07:42:37'),
(101, 33, 12, 4, '2026-04-14 07:42:37'),
(102, 34, 12, 4, '2026-04-14 07:42:37'),
(103, 35, 12, 4, '2026-04-14 07:42:37'),
(104, 36, 12, 4, '2026-04-14 07:42:37'),
(105, 37, 12, 4, '2026-04-14 07:42:37'),
(106, 38, 12, 4, '2026-04-14 07:42:37'),
(107, 39, 12, 4, '2026-04-14 07:42:37'),
(108, 40, 12, 4, '2026-04-14 07:42:37'),
(109, 41, 12, 4, '2026-04-14 07:42:37'),
(110, 42, 12, 4, '2026-04-14 07:42:37'),
(111, 43, 12, 4, '2026-04-14 07:42:37'),
(112, 44, 12, 4, '2026-04-14 07:42:37'),
(113, 45, 12, 4, '2026-04-14 07:42:37'),
(114, 46, 12, 4, '2026-04-14 07:42:37'),
(115, 47, 12, 4, '2026-04-14 07:42:37'),
(116, 48, 12, 4, '2026-04-14 07:42:37'),
(117, 49, 12, 4, '2026-04-14 07:42:37');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `course_code` varchar(20) NOT NULL,
  `subject_name` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`course_code`, `subject_name`) VALUES
('ELECIT103', 'Fundamentals of Database Systems'),
('IT11', 'Introduction to Human Computer Interaction'),
('IT15', 'Networking 2'),
('IT16', 'Quantitative Methods'),
('IT17', 'Networking 1'),
('IT22', 'Information Assurance & Security 1'),
('ITC13', 'Computer Programming 2'),
('ITC15', 'Information Management 1'),
('ITC16', 'Applications Development & Emerging Technologies');

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `teacher_number` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `profile_picture` text DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `address` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teachers`
--

INSERT INTO `teachers` (`id`, `user_id`, `name`, `subject`, `email`, `teacher_number`, `department`, `contact`, `profile_picture`, `bio`, `age`, `address`) VALUES
(13, NULL, 'Andico, LJ', 'SIA', 'lj@gmail.com', NULL, NULL, '09347375393', '', 'Ex lover ni tagulalac', 542, 'Ilog'),
(14, NULL, 'Tagulalac, D', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(15, NULL, 'Alpas, C', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 5, NULL, NULL, NULL, 'TCH-2024-013', 'Information Technology', NULL, NULL, NULL, NULL, NULL),
(17, 6, NULL, NULL, NULL, 'TCH-2024-014', 'Information Technology', NULL, NULL, NULL, NULL, NULL),
(18, 7, NULL, NULL, NULL, 'TCH-2024-015', 'Information Technology', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `teacher_subjects`
--

CREATE TABLE `teacher_subjects` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `section` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teacher_subjects`
--

INSERT INTO `teacher_subjects` (`id`, `teacher_id`, `course_code`, `section`) VALUES
(26, 16, 'IT11', 'BSIT 1A'),
(27, 16, 'ITC15', 'BSIT 2B'),
(28, 17, 'ELECIT103', 'BSIT 2A'),
(29, 17, 'IT22', 'BSIT 3A'),
(30, 18, 'IT16', 'BSIT 2A');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('super_admin','admin','teacher','student') NOT NULL DEFAULT 'student',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `email`, `role`, `status`, `last_login`, `created_at`) VALUES
(1, 'admin', '$2y$10$bkLDCZYCWzWvHB6PWdC0d.W8jwtFhhcME.gzlPYs4IMaTw3v.lDcm', 'System Administrator', 'admin@sams.edu', 'super_admin', 'active', '2026-05-25 02:29:11', '2026-05-20 16:12:15'),
(2, 'lorie', '$2y$10$LKMfZ/ozy98MPxuDgC8g4ez22Gv2OJZIBrmAyXcAjoQT7chdFnRDW', 'Teacher Lorie', 'lorie@sams.edu', 'teacher', 'active', '2026-05-25 02:15:47', '2026-05-20 16:12:15'),
(3, 'dan', '$2y$10$LKMfZ/ozy98MPxuDgC8g4ez22Gv2OJZIBrmAyXcAjoQT7chdFnRDW', 'Sir Dan', 'dan@sams.edu', 'teacher', 'active', '2026-05-25 02:28:26', '2026-05-20 16:12:15'),
(4, 'christine', '$2y$10$LKMfZ/ozy98MPxuDgC8g4ez22Gv2OJZIBrmAyXcAjoQT7chdFnRDW', 'Teacher Christine', 'christine@sams.edu', 'teacher', 'active', '2026-05-25 01:33:18', '2026-05-20 16:12:15'),
(5, 'andico', '$2y$10$LKMfZ/ozy98MPxuDgC8g4ez22Gv2OJZIBrmAyXcAjoQT7chdFnRDW', 'Andico, LJ', NULL, 'teacher', 'active', NULL, '2026-05-21 07:41:43'),
(6, 'tagulalac', '$2y$10$LKMfZ/ozy98MPxuDgC8g4ez22Gv2OJZIBrmAyXcAjoQT7chdFnRDW', 'Tagulalac, D', NULL, 'teacher', 'active', NULL, '2026-05-21 07:41:43'),
(7, 'alpas', '$2y$10$LKMfZ/ozy98MPxuDgC8g4ez22Gv2OJZIBrmAyXcAjoQT7chdFnRDW', 'Alpas, C', NULL, 'teacher', 'active', NULL, '2026-05-21 07:41:43');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_classes`
-- (See below for the actual view)
--
CREATE TABLE `v_classes` (
`class_id` int(11)
,`class_code` varchar(10)
,`section` varchar(20)
,`subject_code` varchar(20)
,`subject_name` varchar(150)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_schedules`
-- (See below for the actual view)
--
CREATE TABLE `v_schedules` (
`schedule_id` int(11)
,`class_id` int(11)
,`day` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday')
,`time` varchar(19)
,`room` varchar(20)
,`instructor` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_students`
-- (See below for the actual view)
--
CREATE TABLE `v_students` (
`id` int(11)
,`student_id` varchar(20)
,`full_name` varchar(100)
,`gender` varchar(10)
,`course` varchar(20)
,`year_level` int(11)
,`section` varchar(20)
,`contact` varchar(20)
,`email` varchar(100)
,`parent_name` varchar(100)
,`parent_contact` varchar(20)
);

-- --------------------------------------------------------

--
-- Structure for view `v_classes`
--
DROP TABLE IF EXISTS `v_classes`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_classes`  AS SELECT `c`.`class_id` AS `class_id`, `c`.`class_code` AS `class_code`, `c`.`section` AS `section`, `c`.`course_code` AS `subject_code`, `sub`.`subject_name` AS `subject_name` FROM (`classes` `c` left join `subjects` `sub` on(`sub`.`course_code` = `c`.`course_code`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_schedules`
--
DROP TABLE IF EXISTS `v_schedules`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_schedules`  AS SELECT `sch`.`schedule_id` AS `schedule_id`, `sch`.`class_id` AS `class_id`, `sch`.`day` AS `day`, concat(time_format(`sch`.`start_time`,'%l:%i %p'),' - ',time_format(`sch`.`end_time`,'%l:%i %p')) AS `time`, `sch`.`room` AS `room`, `u`.`full_name` AS `instructor` FROM (((`schedules` `sch` left join `classes` `c` on(`c`.`class_id` = `sch`.`class_id`)) left join `teachers` `t` on(`t`.`id` = `c`.`teacher_id`)) left join `users` `u` on(`u`.`id` = `t`.`user_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_students`
--
DROP TABLE IF EXISTS `v_students`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_students`  AS SELECT `s`.`id` AS `id`, `s`.`student_number` AS `student_id`, `s`.`full_name` AS `full_name`, `s`.`gender` AS `gender`, `s`.`course` AS `course`, `s`.`year_level` AS `year_level`, `s`.`section` AS `section`, `s`.`contact` AS `contact`, `s`.`email` AS `email`, `p`.`parent_name` AS `parent_name`, `p`.`contact_number` AS `parent_contact` FROM (`students` `s` left join `parents` `p` on(`p`.`student_id` = `s`.`id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD UNIQUE KEY `uq_attendance` (`student_id`,`class_id`,`date`),
  ADD KEY `idx_att_date` (`date`),
  ADD KEY `idx_att_status` (`status`),
  ADD KEY `fk_att_class` (`class_id`),
  ADD KEY `fk_att_teacher` (`teacher_id`);

--
-- Indexes for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_log_student` (`student_id`),
  ADD KEY `idx_log_time` (`logged_at`),
  ADD KEY `idx_log_class` (`class_id`),
  ADD KEY `fk_log_schedule` (`schedule_id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`class_id`),
  ADD KEY `idx_class_course` (`course_code`),
  ADD KEY `fk_class_teacher` (`teacher_id`);

--
-- Indexes for table `dispute_requests`
--
ALTER TABLE `dispute_requests`
  ADD PRIMARY KEY (`dispute_id`),
  ADD KEY `idx_disp_status` (`status`),
  ADD KEY `fk_disp_att` (`attendance_id`),
  ADD KEY `fk_disp_student` (`student_id`),
  ADD KEY `fk_disp_teacher` (`reviewed_by`);

--
-- Indexes for table `message_history`
--
ALTER TABLE `message_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_msg_student` (`student_id`),
  ADD KEY `fk_msg_log` (`log_id`);

--
-- Indexes for table `parents`
--
ALTER TABLE `parents`
  ADD PRIMARY KEY (`parent_id`),
  ADD KEY `idx_parent_student` (`student_id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `idx_sched_class` (`class_id`),
  ADD KEY `idx_in_records` (`in_records`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_number` (`student_number`),
  ADD KEY `idx_student_number` (`student_number`),
  ADD KEY `fk_student_user` (`user_id`);

--
-- Indexes for table `student_schedule`
--
ALTER TABLE `student_schedule`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_student_schedule` (`student_id`,`schedule_id`,`class_id`),
  ADD KEY `fk_ss_schedule` (`schedule_id`),
  ADD KEY `fk_ss_class` (`class_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`course_code`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_teacher_subject` (`teacher_id`,`course_code`,`section`),
  ADD KEY `fk_ts_subject` (`course_code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `class_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `dispute_requests`
--
ALTER TABLE `dispute_requests`
  MODIFY `dispute_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `message_history`
--
ALTER TABLE `message_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parents`
--
ALTER TABLE `parents`
  MODIFY `parent_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `student_schedule`
--
ALTER TABLE `student_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=118;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_att_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_att_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_att_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD CONSTRAINT `fk_log_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_log_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_log_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `fk_class_subject` FOREIGN KEY (`course_code`) REFERENCES `subjects` (`course_code`),
  ADD CONSTRAINT `fk_class_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `dispute_requests`
--
ALTER TABLE `dispute_requests`
  ADD CONSTRAINT `fk_disp_att` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`attendance_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_disp_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_disp_teacher` FOREIGN KEY (`reviewed_by`) REFERENCES `teachers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `message_history`
--
ALTER TABLE `message_history`
  ADD CONSTRAINT `fk_msg_log` FOREIGN KEY (`log_id`) REFERENCES `attendance_logs` (`log_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_msg_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `parents`
--
ALTER TABLE `parents`
  ADD CONSTRAINT `fk_parent_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `fk_sched_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_student_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `student_schedule`
--
ALTER TABLE `student_schedule`
  ADD CONSTRAINT `fk_ss_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ss_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ss_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teachers`
--
ALTER TABLE `teachers`
  ADD CONSTRAINT `fk_teacher_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  ADD CONSTRAINT `fk_ts_subject` FOREIGN KEY (`course_code`) REFERENCES `subjects` (`course_code`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ts_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
