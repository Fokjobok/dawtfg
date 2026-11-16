import FormField from '../components/FormField.jsx'

export default function CreateCoursePage() {
  return (
    <section aria-labelledby="create-course-title">
      <h1 id="create-course-title">Crear curso</h1>
      <p>Completa los datos para preparar un curso nuevo.</p>

      <FormField id="title" label="Título" />

      <FormField as="textarea" id="description" label="Descripción" rows={5} />
    </section>
  )
}
