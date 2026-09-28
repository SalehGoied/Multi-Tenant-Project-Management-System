# Backend Developer Task: Multi-Tenant Project Management System

## Description

You are building a small SaaS platform used by multiple companies. Each company (tenant) has its own users and projects. Data must be isolated between companies, meaning no user should be able to access another company's data.

## Requirements

- The system should support multiple companies using the same application.
- Each company can create projects, view its projects, update project details, and delete projects.
- Each project belongs to a company and can have assigned users.
- A user should only see and manage data that belongs to his company.
- Requests should identify the current company context.
- Implement APIs for the required operations.

## Authentication Requirement (OTP Login)

The system should support passwordless authentication using OTP.

- Users should be able to request a one-time password using their phone number.
- The system should generate and send an OTP code.
- Users should be able to verify the OTP and receive an authentication token.
- OTP codes should have an expiration time.
- The same OTP should not be reusable after successful verification.
- Implement basic protection against excessive OTP requests.

## Expected Deliverables

- Laravel project.
- GitHub repository containing the complete source code.
- Database design.
- API endpoints.
- OTP authentication flow.
- Tenant identification approach.

### README file explaining

- Your database design decisions.
- How you handled tenant isolation.
- Your authentication approach.
- Your OTP implementation decisions.
- Any assumptions you made.
- How to run the project locally.

## Optional (If You Finish Early)

Imagine you now have:

- 10,000 companies.
- Millions of records.

Explain what changes you would make to keep the system scalable.

## Submission Requirements

- GitHub repository link.
- Setup instructions.
- Database migration and seeder files.
- Any notes or decisions made during implementation.

## Evaluation Focus

- Multi-tenancy understanding.
- Database design decisions.
- Tenant data isolation.
- Laravel architecture choices.
- Authentication and OTP flow.
- Problem-solving and scalability thinking.
