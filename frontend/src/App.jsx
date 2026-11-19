import { Route, Routes } from 'react-router'
import AppLayout from './components/AppLayout.jsx'

import AccountPage from './pages/AccountPage.jsx'
import HomePage from './pages/HomePage.jsx'
import AdminPage from './pages/AdminPage.jsx'
import CreateCoursePage from './pages/CreateCoursePage.jsx'
import CoursesPage from './pages/CoursesPage.jsx'

import NotFoundPage from './pages/NotFoundPage.jsx'

import RequireRole from './components/RequireRole.jsx'

export default function App() {
  return (
    <Routes>
      <Route element={<AppLayout />}>
        <Route index element={<HomePage />} />

        <Route path="account" element={<AccountPage />} />

        <Route path="courses" element={<CoursesPage />} />

        <Route path="*" element={<NotFoundPage />} />

        <Route element={<RequireRole role="Administrator" />}>
          <Route path="admin" element={<AdminPage />} />
        </Route>

        <Route element={<RequireRole role={['Coach', 'Administrator']} />}>
          <Route path="courses/create" element={<CreateCoursePage />} />
        </Route>
      </Route>
    </Routes>
  )
}
