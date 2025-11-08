# Data Analytics on Students' Academic Performance in Higher Education

A comprehensive web-based predictive analytics system designed to identify students at risk of failing and forecast minimum scores required to pass, supporting early academic interventions.

## 🎓 Project Overview

This capstone project implements a full-stack web application that uses machine learning (Gradient Boosting) to predict student performance and identify at-risk students in real-time.

### Key Features

- **Role-Based Access Control**: Separate portals for Students, Instructors, and Administrators
- **ML-Powered Predictions**: Gradient Boosting models for risk detection and score forecasting
- **Real-Time Analytics**: Interactive dashboards with performance visualizations
- **Early Warning System**: Automatic notifications for at-risk students
- **Comprehensive Grade Management**: Full CRUD operations for grades, attendance, and assessments
- **Data Visualization**: Charts and graphs for performance tracking
- **Audit Logging**: Complete activity tracking for compliance

## 🛠️ Technology Stack

### Frontend
- HTML5
- Tailwind CSS
- JavaScript
- Chart.js (Data Visualization)
- Font Awesome (Icons)

### Backend
- PHP 7.4+
- MySQL 8.0+
- Python 3.8+ (ML API)

### Machine Learning
- Scikit-learn (Gradient Boosting)
- Pandas (Data Processing)
- NumPy (Numerical Computing)
- Flask (ML API Server)

### Development Tools
- XAMPP (Local Server)
- VS Code (IDE)
- Git (Version Control)

## 📋 System Requirements

### Development Environment
- **Processor**: AMD Ryzen 5 or equivalent
- **RAM**: 16GB
- **OS**: Windows 7/8/10/11
- **Storage**: 5GB free space

### End User Requirements

#### Mobile
- Android device
- 4GB RAM minimum
- Modern web browser

#### Desktop/Laptop
- Intel i3 processor or equivalent
- 4GB RAM minimum
- Windows 7/8/10/11
- Modern web browser (Chrome, Firefox, Edge)

## 🚀 Installation Guide

### Step 1: Install Prerequisites

1. **Install XAMPP**
   - Download from [https://www.apachefriends.org/](https://www.apachefriends.org/)
   - Install with Apache and MySQL modules
   - Start Apache and MySQL services

2. **Install Python**
   - Download Python 3.8+ from [https://www.python.org/](https://www.python.org/)
   - Ensure "Add Python to PATH" is checked during installation

### Step 2: Setup Database

1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Import the database schema:
   - Navigate to the `database` folder
   - Execute `schema.sql` to create the database and tables
   - Default admin credentials will be created automatically

### Step 3: Configure Application

1. **Copy project files**
   ```
   Copy the GradingSystem folder to: C:\xampp\htdocs\
   ```

2. **Update configuration** (if needed)
   - Edit `config/database.php` for database credentials
   - Edit `config/config.php` for application settings
   - Update `ALLOWED_EMAIL_DOMAINS` for your institution

### Step 4: Install Python Dependencies

1. Open Command Prompt/PowerShell
2. Navigate to the ML folder:
   ```bash
   cd C:\xampp\htdocs\GradingSystem\ml
   ```
3. Install required packages:
   ```bash
   pip install -r requirements.txt
   ```

### Step 5: Start ML API Server

1. In the `ml` folder, run:
   ```bash
   python prediction_api.py
   ```
2. The API will start on `http://localhost:5000`
3. Keep this terminal window open while using the system

### Step 6: Access the Application

1. Open your web browser
2. Navigate to: `http://localhost/GradingSystem`
3. Login with default admin credentials:
   - **Email**: admin@university.edu
   - **Password**: admin123

## 👥 User Roles & Features

### Student Portal

- **Dashboard**: Overview of enrolled courses, grades, and predictions
- **Grades View**: Detailed breakdown of all assessment scores
- **Attendance Tracking**: View attendance records and percentages
- **Performance Analytics**: Charts and graphs showing academic progress
- **Risk Alerts**: Notifications when identified as at-risk
- **Historical Records**: Access to past semester performance

### Instructor Portal

- **Class Management**: Create and manage classes
- **Grade Entry**: Input and update student grades
- **Attendance Recording**: Mark daily attendance
- **Student Import/Export**: Bulk upload class lists via CSV
- **At-Risk Identification**: View ML-predicted at-risk students
- **Reports Generation**: Export class performance reports
- **Grading Criteria**: Customize grading systems

### Admin Portal

- **User Management**: Create, update, and manage all user accounts
- **Course Management**: Add and configure courses
- **Grading Systems**: Create institutional grading standards
- **Class Assignment**: Assign instructors to classes
- **System Dashboard**: Institution-wide performance metrics
- **Activity Logs**: Audit trail of all system activities
- **Reports**: Comprehensive institutional reports

## 🤖 Machine Learning Models

### Gradient Boosting Classifier (Risk Detection)

**Purpose**: Identify students at risk of failing

**Features Used**:
- Average score across assessments
- Minimum and maximum scores
- Score standard deviation
- Attendance rate
- Number of absences
- Number of graded components
- Year level
- Course units

**Output**: Risk levels (Low, Medium, High, Critical)

**Evaluation Metrics**:
- Precision
- Recall
- F1-Score

### Gradient Boosting Regressor (Score Forecasting)

**Purpose**: Predict final course scores

**Features Used**: Same as classifier

**Output**: Predicted final grade (0-100)

**Evaluation Metrics**:
- RMSE (Root Mean Squared Error)
- MAE (Mean Absolute Error)

### Model Training

Models are automatically trained when:
- The ML API starts (if models don't exist)
- Manually triggered via `/api/train` endpoint
- Sufficient historical data is available (minimum 10 completed enrollments)

## 📊 API Endpoints

### ML Prediction API

- `POST /api/predict` - Predict for single student
- `POST /api/batch_predict` - Predict for multiple students
- `POST /api/train` - Train/retrain models
- `GET /api/model_info` - Get model information
- `GET /api/health` - Health check

### Application API

- `POST /api/predict_student.php` - Trigger prediction with notifications
- `GET /api/notifications.php` - Get user notifications
- `POST /api/notifications.php` - Mark notification as read
- `PUT /api/notifications.php` - Mark all as read

## 🔒 Security Features

- Password hashing (SHA-256)
- Session management with timeout
- Role-based access control (RBAC)
- SQL injection prevention (Prepared statements)
- XSS protection (Input sanitization)
- Corporate email validation
- Activity logging and auditing

## 📈 Database Schema

### Core Tables

- `users` - All system users
- `students` - Student-specific information
- `instructors` - Instructor-specific information
- `courses` - Course catalog
- `classes` - Course instances per semester
- `enrollments` - Student-class relationships
- `grades` - Assessment scores
- `attendance` - Attendance records
- `predictions` - ML prediction results
- `notifications` - User notifications
- `activity_logs` - System audit trail
- `grading_systems` - Grading configurations
- `grading_criteria` - Grading components

## 🎯 Usage Workflow

### For Students

1. Login with corporate email
2. View dashboard with current courses
3. Check grades and attendance
4. Review ML predictions and risk levels
5. Monitor performance analytics
6. Receive notifications for updates

### For Instructors

1. Login and create/manage classes
2. Import student lists (CSV)
3. Record attendance regularly
4. Input grades for assessments
5. Review at-risk student predictions
6. Generate and export reports
7. Provide early interventions

### For Administrators

1. Login to admin portal
2. Create user accounts (students/instructors)
3. Manage courses and grading systems
4. Assign instructors to classes
5. Monitor institutional performance
6. Review activity logs
7. Generate system-wide reports

## 📁 Project Structure

```
GradingSystem/
├── admin/                  # Admin portal
│   ├── dashboard.php
│   ├── manage_users.php
│   ├── manage_courses.php
│   └── includes/
├── instructor/             # Instructor portal
│   ├── dashboard.php
│   ├── classes.php
│   ├── manage_grades.php
│   └── includes/
├── student/                # Student portal
│   ├── dashboard.php
│   ├── grades.php
│   ├── performance.php
│   └── includes/
├── api/                    # API endpoints
│   ├── predict_student.php
│   └── notifications.php
├── config/                 # Configuration files
│   ├── config.php
│   └── database.php
├── database/               # Database schemas
│   └── schema.sql
├── includes/               # Shared PHP files
│   ├── auth.php
│   └── functions.php
├── ml/                     # Machine Learning
│   ├── prediction_api.py
│   ├── requirements.txt
│   └── models/
├── login.php
├── register.php
├── logout.php
└── index.php
```

## ⚠️ Limitations

1. **Data Scope**: Predictions rely solely on quantitative academic data (grades, attendance)
2. **External Factors**: Does not account for socio-economic or mental health factors
3. **Data Dependency**: Accuracy depends on timely and accurate instructor inputs
4. **Platform**: Web-only deployment (no native mobile app)
5. **Scope**: Limited to selected departments/programs initially
6. **Historical Data**: Requires sufficient historical data for accurate predictions

## 🔧 Troubleshooting

### Database Connection Issues
- Verify MySQL is running in XAMPP
- Check database credentials in `config/database.php`
- Ensure database exists and schema is imported

### ML API Not Working
- Verify Python is installed and in PATH
- Check all dependencies are installed: `pip install -r requirements.txt`
- Ensure ML API is running: `python prediction_api.py`
- Check port 5000 is not blocked by firewall

### Login Issues
- Use default admin credentials for first login
- Ensure email domain is in `ALLOWED_EMAIL_DOMAINS`
- Clear browser cache and cookies

### Predictions Not Generating
- Ensure ML API is running
- Check sufficient training data exists (10+ completed enrollments)
- Manually train models via `/api/train` endpoint

## 📞 Support

For technical support or questions:
- Review this documentation
- Check error logs in `php_error.log`
- Verify all services are running (Apache, MySQL, Python API)

## 📝 License

This project is developed as a capstone project for educational purposes.

## 👨‍💻 Development Team

Capstone Project - Data Analytics on Students' Academic Performance in Higher Education

---

**Version**: 1.0.0  
**Last Updated**: 2025  
**Status**: Production Ready
